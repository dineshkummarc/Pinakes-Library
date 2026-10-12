<?php
declare(strict_types=1);
/** Issues 467–469: disposable tables, real saves/migration and rendered views. */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/EmerotecaPlugin.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Controllers/PeriodicalAdminController.php';
require dirname(__DIR__) . '/storage/plugins/emeroteca/src/Controllers/PublicController.php';

use App\Plugins\Emeroteca\Controllers\PeriodicalAdminController;
use App\Plugins\Emeroteca\Services\PeriodicalPublisherService;
use App\Support\HookManager;

final class PeriodicalIssuesDb extends mysqli
{
    public string $prefix;
    public array $tables = ['emeroteca_testate_editori','emeroteca_testate','emeroteca_annate','emeroteca_fascicoli','emeroteca_articoli','emeroteca_abbonamenti','emeroteca_contributi_autori','emeroteca_contributi','plugin_settings','plugins','editori'];
    public function map(string $sql): string
    {
        foreach ($this->tables as $table) { $sql = preg_replace('/\b'.preg_quote($table,'/').'\b/', $this->prefix.$table, $sql); }
        return preg_replace('/\b(fk_emeroteca_\w+|fk_contributo_\w+)\b/', $this->prefix.'$1', $sql);
    }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool { return parent::query($this->map($query), $result_mode); }
    public function prepare(string $query): mysqli_stmt|false { return new PeriodicalIssuesStmt($this, $this->map($query)); }
}
final class PeriodicalIssuesStmt extends mysqli_stmt
{
    private array $values = [];
    public function __construct(private PeriodicalIssuesDb $sandbox, string $sql) { parent::__construct($sandbox,$sql); }
    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->values = array_map(fn($v) => is_string($v) && in_array($v,$this->sandbox->tables,true) ? $this->sandbox->prefix.$v : $v, $vars);
        return parent::bind_param($types, ...$this->values);
    }
}
$env = Dotenv\Dotenv::parse((string)file_get_contents(dirname(__DIR__).'/.env'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new PeriodicalIssuesDb($env['DB_HOST']??'localhost',getenv('E2E_DB_USER')?:$env['DB_USER'],getenv('E2E_DB_PASS')?:($env['DB_PASS']??$env['DB_PASSWORD']),getenv('E2E_DB_NAME')?:$env['DB_NAME'],(int)($env['DB_PORT']??3306),getenv('E2E_DB_SOCKET')?:($env['DB_SOCKET']??null));
$db->prefix = 'zz467_'.bin2hex(random_bytes(3)).'_'; $db->set_charset('utf8mb4');
$_SESSION = [];
$count = 0;
$check = static function(bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo 'PASS '.(++$count).' '.$label."\n";
};
$render = static function(string $file, array $data): string {
    extract($data, EXTR_SKIP); ob_start();
    include dirname(__DIR__).'/storage/plugins/emeroteca/src/Views/'.$file;
    return (string)ob_get_clean();
};
try {
    // Clone only an empty authority table; all writes stay in this sandbox.
    $db->real_query('CREATE TABLE '.$db->prefix.'editori LIKE editori');
    $db->query('CREATE TABLE plugins (id INT PRIMARY KEY,name VARCHAR(50))');
    $db->query("CREATE TABLE plugin_settings (plugin_id INT,setting_key VARCHAR(100),setting_value TEXT,PRIMARY KEY(plugin_id,setting_key))");
    $db->query("INSERT INTO plugins VALUES (1,'emeroteca')");
    $db->query(EmerotecaPlugin::ddlTestate());
    $db->query('ALTER TABLE emeroteca_testate MODIFY direttore_responsabile VARCHAR(255) NULL');
    $db->query("INSERT INTO editori(nome) VALUES ('First publisher'),('Second publisher')");
    $db->query("INSERT INTO emeroteca_testate(titolo,editore_id,direttore_responsabile) VALUES ('Legacy masthead',1,'Schweissinger, Marc J.')");
    $legacyId = (int)$db->insert_id;
    $plugin = new EmerotecaPlugin($db,new HookManager($db));
    $check($plugin->ensureSchema()['failed'] === [], 'legacy schema upgrades');
    $check($plugin->ensureSchema()['failed'] === [], 'upgrade is idempotent');
    $legacy = $db->query('SELECT t.*, e.nome editore_nome FROM emeroteca_testate t LEFT JOIN editori e ON e.id=t.editore_id')->fetch_assoc();
    $service = new PeriodicalPublisherService($db);
    $legacy = $service->hydrate([$legacy])[0];
    $check($legacy['editori_ids'] === [1] && $legacy['direttore_responsabile'] === 'Schweissinger, Marc J.', 'legacy publisher and comma in director credit survive');
    $controller = new PeriodicalAdminController($db,new HookManager($db));
    $validate = new ReflectionMethod($controller,'validate');
    $save = new ReflectionMethod($controller,'savePeriodical');
    $body = ['titolo'=>'Multiple credits','editori_present'=>'1','editori_ids'=>['2','1','new:Third publisher'], 'direttori_present'=>'1','direttori'=>['Schweissinger, Marc J.',str_repeat('B',200)]];
    [$values,$errors] = $validate->invoke($controller,$body,null);
    $check($errors === [], 'multiple publisher and director inputs validate');
    $args = [&$values,null]; $id = $save->invokeArgs($controller,$args);
    $row = $db->query('SELECT t.*,e.nome editore_nome FROM emeroteca_testate t LEFT JOIN editori e ON e.id=t.editore_id WHERE t.id='.$id)->fetch_assoc();
    $row = $service->hydrate([$row])[0];
    $check(array_column($row['editori'],'nome') === ['Second publisher','First publisher','Third publisher'], 'all three publishers save and reload in order');
    $check((int)$row['editore_id'] === 2 && $row['direttore_responsabile'] === implode('; ',$body['direttori']), 'primary publisher and all directors save without truncation');
    $html = $render('form.php',['values'=>$row,'errors'=>[],'editori'=>[1=>'First publisher',2=>'Second publisher',3=>'Third publisher'],'generi'=>[],'testate'=>[]]);
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    $selected = (new DOMXPath($dom))->query('//select[@id="periodical-publishers-select"]/option[@selected]');
    $selectedIds = []; foreach ($selected as $option) { $selectedIds[] = $option->getAttribute('value'); }
    $check($selectedIds === ['2','1','3'], 'opening the form preserves saved publisher order and the primary publisher');
    [$values,$errors] = $validate->invoke($controller,['titolo'=>'Multiple credits','editori_present'=>'1','editori_ids'=>['1','1'],'direttori_present'=>'1','direttori'=>[]],$id);
    $args=[&$values,$id];$save->invokeArgs($controller,$args);
    $check((int)$db->query('SELECT COUNT(*) FROM emeroteca_testate_editori WHERE testata_id='.$id)->fetch_row()[0] === 1, 'removal and duplicate selections leave one publisher');
    $check($db->query('SELECT direttore_responsabile FROM emeroteca_testate WHERE id='.$id)->fetch_row()[0] === null, 'removing every director clears the field');
    [$values,$errors] = $validate->invoke($controller,['titolo'=>'Empty credits','editori_present'=>'1','direttori_present'=>'1'],$id);
    $args=[&$values,$id];$save->invokeArgs($controller,$args);
    $check($db->query('SELECT editore_id FROM emeroteca_testate WHERE id='.$id)->fetch_row()[0] === null && (int)$db->query('SELECT COUNT(*) FROM emeroteca_testate_editori WHERE testata_id='.$id)->fetch_row()[0] === 0, 'removing every publisher clears primary and links');
    foreach (['99999999',['bad'],'new:'.str_repeat('x',256)] as $invalid) {
        [, $errors] = $validate->invoke($controller,['titolo'=>'Bad','editori_ids'=>[$invalid]],null);
        $check(isset($errors['editori_ids']), 'invalid publisher is rejected');
    }
    [, $errors] = $validate->invoke($controller,['titolo'=>'Bad','direttori'=>[str_repeat('x',256)]],null);
    $check(isset($errors['direttore_responsabile']), 'oversized director is rejected');
    // Prove a failed publisher-link write rolls back both the masthead and the authority.
    [$values,$errors]=$validate->invoke($controller,['titolo'=>'Must roll back','editori_ids'=>['new:Rolled back publisher']],null);
    $db->query('DROP TABLE emeroteca_testate_editori');
    try { $args=[&$values,null];$save->invokeArgs($controller,$args); $check(false,'failed link save must throw'); } catch (RuntimeException $e) {}
    $check((int)$db->query("SELECT COUNT(*) FROM emeroteca_testate WHERE titolo='Must roll back'")->fetch_row()[0] === 0 && (int)$db->query("SELECT COUNT(*) FROM editori WHERE nome='Rolled back publisher'")->fetch_row()[0] === 0, 'failed save rolls back masthead and new authority');
    $db->query(PeriodicalPublisherService::ddl());
    $service->replace($legacyId,[1,2]);
    $service->merge(2,[1]);
    $check((int)$db->query('SELECT COUNT(*) FROM emeroteca_testate_editori WHERE testata_id='.$legacyId)->fetch_row()[0] === 1, 'publisher merge collapses duplicate links');
    $service->replace($legacyId,[1,3,2]);
    $db->query('UPDATE emeroteca_testate SET editore_id=1 WHERE id='.$legacyId);
    $plugin->onPublisherMerging(2,[1]);
    $afterMerge = $service->hydrate([$db->query('SELECT t.*,e.nome editore_nome FROM emeroteca_testate t LEFT JOIN editori e ON e.id=t.editore_id WHERE t.id='.$legacyId)->fetch_assoc()])[0];
    $check($afterMerge['editori_ids'] === [2,3] && (int)$afterMerge['editore_id'] === 2, 'publisher merge preserves the earliest credit and keeps primary ID and order aligned');
    $service->replace($legacyId,[1,2]);
    $service->beforeDelete(1);
    $check((int)$db->query('SELECT editore_id FROM emeroteca_testate WHERE id='.$legacyId)->fetch_row()[0] === 2, 'deleting the primary promotes the remaining publisher');
    $service->replace($legacyId,[1,2]);
    $sourceTitle = $service->hydrate([$db->query('SELECT t.*, e.nome editore_nome FROM emeroteca_testate t LEFT JOIN editori e ON e.id=t.editore_id WHERE t.id='.$legacyId)->fetch_assoc()])[0];
    $targetTitle = $db->query('SELECT * FROM emeroteca_testate WHERE id='.$id)->fetch_assoc();
    $service->mergeTitles($sourceTitle,$targetTitle);
    $merged = $service->hydrate([$db->query('SELECT t.*,e.nome editore_nome FROM emeroteca_testate t LEFT JOIN editori e ON e.id=t.editore_id WHERE t.id='.$id)->fetch_assoc()])[0];
    $check($merged['editori_ids'] === [1,2], 'masthead merge preserves publishers from both titles');
    $public = new \App\Plugins\Emeroteca\Controllers\PublicController($db,new HookManager($db));
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET','http://localhost/emeroteca')->withQueryParams(['editore'=>2]);
    $response = $public->index($request,(new \Slim\Psr7\Factory\ResponseFactory())->createResponse());
    $check($response->getStatusCode() === 200 && str_contains((string)$response->getBody(),'Empty credits') && str_contains((string)$response->getBody(),'Second publisher'), 'public publisher filter includes a secondary publisher and its facet');
    $facetDom=new DOMDocument();@$facetDom->loadHTML((string)$response->getBody());
    $facetPath=new DOMXPath($facetDom);
    $expectedCount=(int)$db->query('SELECT COUNT(*) FROM emeroteca_testate t WHERE t.editore_id=2 OR EXISTS (SELECT 1 FROM emeroteca_testate_editori te WHERE te.testata_id=t.id AND te.editore_id=2)')->fetch_row()[0];
    $countNode=$facetPath->query('//a[contains(@href,"editore=2")]/span[contains(@class,"count-badge")]')->item(0);
    $check($countNode!==null && (int)$countNode->textContent===$expectedCount, 'publisher facet counts overlapping primary and secondary links only once');
    require_once dirname(__DIR__).'/storage/plugins/emeroteca/src/Support/KbartExporter.php';
    $fetch = new ReflectionMethod(\App\Plugins\Emeroteca\Support\KbartExporter::class,'fetchTestate');
    $exportRows = $fetch->invoke(null,$db,$id);
    $check($exportRows[0]['editore_nome'] === 'First publisher; Second publisher', 'holdings export carries every publisher');
    require_once dirname(__DIR__).'/storage/plugins/emeroteca/src/Modules/MobileModule.php';
    $module = new \App\Plugins\Emeroteca\Modules\MobileModule($db);
    $map = new ReflectionMethod($module,'mapPeriodicalItem');
    $mobile = $map->invoke($module,$merged);
    $check(count($mobile['publishers']) === 2 && $mobile['publisher']['name'] === 'First publisher', 'mobile adds all publishers and keeps its primary publisher contract');
    // A plain legacy save must retain the complete list when the primary is unchanged.
    [$values,$errors] = $validate->invoke($controller,['titolo'=>'Legacy save','editore_id'=>'1'], $id);
    $args=[&$values,$id];$save->invokeArgs($controller,$args);
    $check((int)$db->query('SELECT COUNT(*) FROM emeroteca_testate_editori WHERE testata_id='.$id)->fetch_row()[0] === 2, 'legacy scalar save preserves secondary publishers');
    $db->begin_transaction();
    $db->query("INSERT INTO emeroteca_testate(titolo) VALUES ('Outer transaction')");
    [$values,$errors]=$validate->invoke($controller,['titolo'=>'Nested save','editori_ids'=>['1','2']],null);
    $args=[&$values,null];$save->invokeArgs($controller,$args);
    $db->rollback();
    $check((int)$db->query("SELECT COUNT(*) FROM emeroteca_testate WHERE titolo IN ('Outer transaction','Nested save')")->fetch_row()[0] === 0, 'nested save does not commit its caller transaction');
    // Merging two legal lists can produce a longer legacy list; opening/saving it must still work.
    $longIds = [1,2,3];
    for ($n=4; $n<=21; $n++) {
        $db->query("INSERT INTO editori(nome) VALUES ('Merged publisher ".$n."')");
        $longIds[] = (int)$db->insert_id;
    }
    $service->replace($id,$longIds);
    $longBody=['titolo'=>'Large merged masthead','editori_ids'=>array_map('strval',$longIds)];
    [$values,$errors] = $validate->invoke($controller,$longBody,$id);
    $check($errors === [], 'a merged list longer than the picker limit can be kept unchanged');
    $args=[&$values,$id];$save->invokeArgs($controller,$args);
    $check((int)$db->query('SELECT COUNT(*) FROM emeroteca_testate_editori WHERE testata_id='.$id)->fetch_row()[0] === 21, 'saving a large merged list keeps every publisher');
    $longBody['editori_ids'][]='new:Outside the limit';
    [, $errors]=$validate->invoke($controller,$longBody,$id);
    $check(isset($errors['editori_ids']), 'a long legacy list cannot bypass the limit to add new publishers');
    // Escaped public placement is identical for anonymous readers and administrators.
    $article=['id'=>469,'titolo'=>'Article','contenitore_titolo'=>'Siden Saxo. Magasin for dansk historie','volume'=>'4','numero'=>'1','pagine'=>'10-17','keywords'=>'history, politics'];
    $placements=[];
    foreach ([false,true] as $admin) {
        $_SESSION=$admin?['user'=>['tipo_utente'=>'admin']]:[];
        $html=$render('public/article.php',['article'=>$article,'canEdit'=>$admin]);
        preg_match('/<p class="resource-placement">(.*?)<\/p>/s',$html,$m);$placements[]=$m[1]??'';
        $check(str_contains($placements[array_key_last($placements)],'<strong>'.__('Volume').'</strong> 4') && str_contains($html,'<strong>'.__('Numero').'</strong> 1') && str_contains($html,'<strong>'.__('Pagine').'</strong> 10-17'), 'public placement labels all bibliographic numbers for '.($admin?'admin':'visitor'));
        $check(str_contains($html,'/admin/periodicals/articles/469/edit') === $admin, 'only staff sees the public edit action');
    }
    $check($placements[0] === $placements[1], 'login does not change public placement');
    $article['fascicolo_id']=3;$article['fascicolo_numero']='1';$article['fascicolo_anno']=1987;
    $html=$render('public/article.php',['article'=>$article]);
    $check(str_contains($html,'<strong>'.__('Volume').'</strong> 4') && str_contains($html,'<strong>'.__('Numero').'</strong> <a') && str_contains($html,'/fascicolo/3'), 'linked issue retains volume and labelled issue link');
    $article['numero']='9';
    $html=$render('public/article.php',['article'=>$article]);
    preg_match('/<p class="resource-placement">(.*?)<\/p>/s',$html,$m);
    $check(str_contains($m[1]??'', '<strong>'.__('Numero').'</strong> 9') && !str_contains($m[1]??'', '/fascicolo/3'), 'a different article number is not linked to the wrong issue');
    $article['volume']='0';$article['numero']='';$article['pagine']='<script>alert(1)</script>';
    $html=$render('public/article.php',['article'=>$article]);
    $check(str_contains($html,'<strong>'.__('Volume').'</strong> 0') && !str_contains($html,'<script>alert(1)</script>'), 'zero volume and unsafe text render safely');
    $article['keywords']='history, <script>alert(1)</script>';
    $html=$render('article-show.php',['row'=>$article,'isAdmin'=>true]);
    $check(substr_count($html,'fas fa-tag mr-1') === 2 && str_contains($html,'articles?q=history') && !str_contains($html,'<script>alert(1)</script>'), 'admin keywords use book tag icons, working search links and escaped names');
    $html=$render('form.php',['values'=>$legacy,'errors'=>[],'editori'=>[1=>'First publisher',2=>'Second publisher'],'generi'=>[],'testate'=>[]]);
    $check(str_contains($html,'name="editori_ids[]"') && str_contains($html,'data-periodical-credit="director"') && str_contains($html,'emeroteca-credits.js'), 'form has both multiple credit pickers');
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_reverse($db->tables) as $table) { $db->real_query('DROP TABLE IF EXISTS '.$db->prefix.$table); }
    $db->query('SET FOREIGN_KEY_CHECKS=1');
}
echo "SUCCESS {$count} checks\n";
