<?php
// Генерирует файл входов: то, что зовёт фреймворк, — first-class callable по публичным методам.
define('LARAVEL_START',microtime(true));
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out="<?php\n// сгенерировано, не коммитить\n"; $i=0; $seen=[];
$emit=function(string $c,array $ms) use(&$out,&$i){
  if(!$ms) return; $b=''; foreach(array_unique($ms) as $m){$b.="    \$o->$m(...);\n";}
  $i++; $out.="function psalm_entry_$i(\\$c \$o): void {\n$b}\n";
};
foreach(Illuminate\Support\Facades\Route::getRoutes() as $r){
  $u=$r->getAction('uses'); if(!is_string($u)||!str_contains($u,'@')) continue;
  [$c,$m]=explode('@',$u); if(!str_starts_with($c,'App\\')&&!str_starts_with($c,'Streeboga\\')) continue;
  $seen[$c][]=$m;
}
foreach($seen as $c=>$ms){ if(!method_exists($c,$ms[0])) continue; $emit($c,$ms);}
$dirs=['app/Console','app/Jobs','app/Listeners','app/Policies','app/Http/Middleware','app/Http/Requests','app/Http/Resources','app/Filament','app/Mail','app/Notifications','app/Providers','app/Rules','app/Casts','app/Observers','app/Models','app/Support/Scramble','app/Features','app/Enums','app/Exceptions','app/Events','app/Livewire','database/seeders','database/factories'];
foreach(glob('packages/streeboga/*/src') as $p) foreach(['Console','Jobs','Listeners','Policies','Http','Mail','Notifications','Providers','Models','Domain/Models','Domain/Notifications','Events','Enums'] as $d) $dirs[]="$p/$d";
foreach(glob('packages/streeboga/*/src/*ServiceProvider.php') as $f){$dirs[]=$f;}
$files=[]; foreach($dirs as $d){ if(is_file($d)){$files[]=$d;continue;} if(!is_dir($d)) continue;
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS)) as $f) if($f->getExtension()==='php') $files[]=$f->getPathname();}
$fw=['handle','__invoke','boot','register','rules','authorize','messages','attributes','toArray','toAttributes','toRelationships','toLinks','toMeta','casts','definition','run','via','toMail','toDatabase','toTelegram','broadcastOn','broadcastAs','broadcastWith','failed','retryUntil','backoff','middleware','uniqueId','tags','viewAny','view','create','update','delete','restore','forceDelete','before','validate','form','table','infolist','getPages','getRelations','canAccess','getNavigationBadge','getEloquentQuery','mount','render','content','envelope','build','attachments','toSearchableArray','shouldBeSearchable','makeSearchableUsing','searchableAs','afterSave','prepareForValidation','passedValidation','failedValidation','withValidator','newFactory','newEloquentBuilder','resolve','send','schema','getTitle','getHeading','getView','getHeaderActions','getHeaderWidgets','getTableColumns','afterCreate','beforeSave','mutateFormDataBeforeSave','getFormActions','getLabel','getColor','getIcon','getDescription'];
foreach($files as $f){
  $src=file_get_contents($f);
  if(!preg_match('/^namespace\s+([^;]+);/m',$src,$n)||!preg_match('/^(?:abstract\s+|final\s+|readonly\s+)*(?:class|enum)\s+(\w+)/m',$src,$k)) continue;
  $c=$n[1].'\\'.$k[1]; if(!class_exists($c)&&!enum_exists($c)) continue;
  $r=new ReflectionClass($c); $ms=[];
  foreach($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m){
    if($m->getDeclaringClass()->getName()!==$c||$m->isStatic()||str_starts_with($m->name,'__')) continue;
    $isModelMagic=$r->isSubclassOf(Illuminate\Database\Eloquent\Model::class)&&($m->getReturnType()&&is_a((string)$m->getReturnType(),Illuminate\Database\Eloquent\Relations\Relation::class,true)||(string)$m->getReturnType()===Illuminate\Database\Eloquent\Casts\Attribute::class);
    if(in_array($m->name,$fw,true)||$isModelMagic) $ms[]=$m->name;
  }
  if($r->isAbstract()) continue;
  $emit($c,$ms);
  if(!$ms){$i++; $out.="function psalm_entry_$i(\\$c \$o): void {}\n";}
}
file_put_contents($argv[1],$out); echo "$i входов\n";
