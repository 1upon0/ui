<?php
namespace ui;
include($_APP_DIR.'inc/constants.php');

date_default_timezone_set(TIMEZONE);
function load_lib($name)
{
	$slug='LIB_'.strtoupper($name);
	if(defined($slug))
		return true;
	if(!load_php(dirname(__FILE__).'/../lib/lib_'.$name.'.php'))
	{
		if(!load_php(dirname(__FILE__).'/../lib/'.$name.'/lib_'.$name.'.php'))
		{
      if(global_var('imported_app'))
      {
        if(!load_php(global_var('app_dir').'lib/lib_'.$name.'.php'))
        {
          if(!load_php(global_var('app_dir').'lib/'.$name.'/lib_'.$name.'.php'))
          {
            log("error: loading lib $name: not found\n");
      			return false;
      		}
      	}
      }else{
        log("error: loading lib $name: not found\n");
        return false;
      }
		}
	}
	define($slug,true);
  $auto_load='\\'.__NAMESPACE__."\\$name\\auto";
  try{
    if(DEBUG){
      if(function_exists("$auto_load::testSuite()"))
        $auto_load::testSuite();
    }
    if(function_exists("$auto_load::init()"))
       $auto_load::init();
  }catch(\Exception $e){
    log("Loading lib $auto_load: ".$e->getMessage());
    exit();
  }
	return true;
}
/**
* include this in the lib: class auto{const load=0;public static function testSuite(){optional tests for debug mode}public static function init(){init code}}
* call this to load a lib: \ui\lib_name\auto::load;
*/
spl_autoload_register(function($class){
  $l=strlen(__NAMESPACE__)+1;
  if(substr($class,0,$l)!=__NAMESPACE__)//namespace must start with ui\
    return;
  $lib=substr($class,$l,strrpos($class, '\\')-$l);
  if(!$lib)
    return;
  echo "loading $lib";
  load_lib($lib);
});

function load_php($file)
{
	if(file_exists($file))
	{
		include $file;
		return true;
	}
	return false;
}

function config($key=false,$val=NULL,$set=false)
{
	static $config=NULL;
	if(!$config)
	{
		$config=array();
		include(global_var('app_dir').'inc/config.php');
	}
	if($key===false)
		return $config;
	if($set)
		$config[$key]=$val;
	if(isset($config[$key]))
		return $config[$key];
	return $val;
}
/**
 * $silent suppresses output in DEBUG mode. In production, always silent.
 */
function log($msg,$silent=false)
{
  if(DEBUG&&!$silent){
      echo '<pre>',date('d/m/y h:i:saP')," ".htmlspecialchars($msg,ENT_QUOTES).'</pre>';
  }
  file_put_contents(global_var('app_dir').LOG_FILE,date('d/m/y h:i:saP')." $msg\n",FILE_APPEND);
}
function benchmark($tag='',$send=false)
{
	if(PRODUCTION||defined('NO_TEMPLATE')||defined('NO_BENCHMARK'))
		return;
	static $start_time=0,$start_mem=0,$last_mem=0,$last_time=0,$now_time=0,$now_mem=0,$output='';
	if($send)
	{
		?><script>console.log('Benchmark Results');console.log(<?php echo json_encode($output) ?>);</script>
		<?php $output='';
		return;
	}
	$now_time=microtime(1);
	$now_mem=memory_get_usage();
	$now_mem_peak=memory_get_peak_usage();
	if(!$start_time)
	{
		$start_time=$last_time=$now_time;
		$start_mem=$last_mem=$now_mem;
		$output.=sprintf('Memory::start:[%dKB]',$start_mem/1024).PHP_EOL;
		return;
	}
	$output.='=['.$tag.']='.PHP_EOL;
	$output.=sprintf('Time|    init|  %.2f ms ',($now_time-$start_time)*1000).PHP_EOL;
	$output.=sprintf('    |    prev| %+.2f ms ',($now_time-$last_time)*1000).PHP_EOL;
	$output.=sprintf('Mem |   start| %+d KB ',($now_mem-$start_mem)/1024).PHP_EOL;
	$output.=sprintf('    |    prev| %+d KB',($now_mem-$last_mem)/1024).PHP_EOL;
	$output.=sprintf('    | abs now|  %d KB',$now_mem/1024).PHP_EOL;
	$output.=sprintf('    |abs peak|  %d KB',$now_mem_peak/1024).PHP_EOL;

	$last_time=$now_time;
	$last_mem=$now_mem;
}
function on_exit()
{
	execute_hook('content_end');
  benchmark('Everything Done. Will send output to template.php');
	benchmark(NULL,true);
  $ctrl=global_var('controller');
  while($ctrl!==false){
    $ctrl=substr($ctrl,0,strrpos($ctrl,'/'));
    $template=config('base_path').'app/'.$ctrl.'/_template.php';
    if(file_exists($template))
    {
      global_var('template_file',$template,1);
      include($template);
      break;
    }
  }
  if(!isset($_SESSION['new_flash']))$_SESSION['new_flash']=array();
	$_SESSION['flash']=$_SESSION['new_flash'];
	unset($_SESSION['new_flash']);
	execute_hook('exit');
}
function get_url($name='path')//returns absolute URL to a controller
{
	if($name=='path')$name=global_var('path');
	return config('base_url').$name;
}
function relative_base_url(){
  $base=dirname($_SERVER['SCRIPT_NAME']);
  if(UI_URL_REWRITE)
    $base=substr($base,0,-7);// remove /public
  else
    $base.='/index.php/';
  $base=rtrim($base,'/').'/';//relative URL to base directory
  return $base;    
}
function &global_var($key=false,$val=NULL,$set=false)
{
	static $vars=NULL;
	if(!$vars)$vars=array();
	if($key===false)return $vars;
	if($set){
		$vars[$key]=$val;
	}
	if(isset($vars[$key]))
  	return $vars[$key];
	$vars[$key]=$val;
  return $vars[$key];
}
function register_hook($hook,$callback)
{
	$hooks=&global_var('hooks');
	if($hooks===NULL)$hooks=&global_var('hooks',array(),true);
	$hooks[$hook][]=$callback;
}
function execute_hook($hook,&$param=array())
{
	$hooks=&global_var('hooks');
	if($hooks===NULL)
		$hooks=&global_var('hooks',array(),true);
	if(!isset($hooks[$hook][0]))
		return;
	foreach($hooks[$hook] as $callback)
	{
		$ret=$callback($param);
	}
  return $ret;
}
function set_flash($key,$value,$append=true)
{
	if(!isset($_SESSION['new_flash']))
		$_SESSION['new_flash']=array();
  if(isset($_SESSION['new_flash'][$key])&&$append)
    $_SESSION['new_flash'][$key].="<br/>\n".$value;
	$_SESSION['new_flash'][$key]=$value;
}
function get_flash($key=false,$default=NULL)
{
	if(!isset($_SESSION['flash']))
		return NULL;
	if($key!==false&&isset($_SESSION['flash'][$key]))
		return $_SESSION['flash'][$key];
	if($key===false)
		return $_SESSION['flash'];
	return $default;
}