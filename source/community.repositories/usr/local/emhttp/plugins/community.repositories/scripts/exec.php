<?
$plugin = "community.repositories";
require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/dockerClient.php");
$DockerTemplates = new DockerTemplates();
$dockerManPaths['community-templates-url']  = "https://raw.githubusercontent.com/Squidly271/repo.update/master/Repositories.json";
$dockerManPaths['templates-community']      = "/var/lib/docker/unraid/templates-community";
$dockerManPaths['community-templates-info'] = "/var/lib/docker/unraid/templates-community/templates.json";
$infoFile                                   = $dockerManPaths['community-templates-info'];
$docker_repos                               = $dockerManPaths['template-repos'];

# Make sure the link is in place
if (is_dir("/usr/local/emhttp/state/plugins/${plugin}")) shell_exec("rm -rf /usr/local/emhttp/state/plugins/${plugin} &>/dev/null");
if (!is_link("/usr/local/emhttp/state/plugins/${plugin}")) symlink($dockerManPaths['templates-community'], "/usr/local/emhttp/state/plugins/${plugin}");

class Community {

  public $verbose = false;

  private function debug($m) {
    if($this->verbose) echo $m.PHP_EOL;
  }

  private function removeDir($path){
    if (is_dir($path) === true) {
      $files = array_diff(scandir($path), array('.', '..'));
      foreach ($files as $file) {
        $this->removeDir(realpath($path) . '/' . $file);
      }
      return rmdir($path);
    } else if (is_file($path) === true) {
      return unlink($path);
    }
    return false;
  }

  public function listDir($root) {
    $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, 
            RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD);
    $paths = array();
    foreach ($iter as $path => $fileinfo) {
      if ( $fileinfo->isFile()) $paths[] = array('path' => $path, 'prefix' => basename(dirname($path)), 'name' => $fileinfo->getBasename(".".$fileinfo->getExtension()));
    }
    return $paths;
  }

  private function build_sorter($key) {
    return function ($a, $b) use ($key) {
      return strnatcmp(strtolower($a[$key]), strtolower($b[$key]));
    };
  }

  public function download_url($url, $path = "", $bg = FALSE){
    exec("curl --max-time 60 --silent --insecure --location --fail ".($path ? " -o '$path' " : "")." $url ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code );
    return ($exit_code === 0 ) ? implode("\n", $out) : NULL;
  }

  public function downloadTemplates($Dest=NULL, $Urls=NULL){
    global $dockerManPaths;
    $Dest = ($Dest) ? $Dest : $dockerManPaths['templates-storage'];
    $Urls = ($Urls) ? $Urls : $dockerManPaths['template-repos'];
    $repotemplates = array();
    $output = array();
    $tmp_dir = "/tmp/tmp-".mt_rand();
    
    $urls = @file($Urls, FILE_IGNORE_NEW_LINES);
    if ( ! is_array($urls)) return false;
    $this->debug("\nURLs:\n   " . implode("\n   ", $urls));
    foreach ($urls as $url) {
      $api_regexes = array(
        0 => '%/.*github.com/([^/]*)/([^/]*)/tree/([^/]*)/(.*)$%i',
        1 => '%/.*github.com/([^/]*)/([^/]*)/tree/([^/]*)$%i',
        2 => '%/.*github.com/([^/]*)/(.*).git%i',
        3 => '%/.*github.com/([^/]*)/(.*)%i',
        );
      for ($i=0; $i < count($api_regexes); $i++) {
        if ( preg_match($api_regexes[$i], $url, $matches) ){
          $github_api['user']   = ( isset( $matches[1] )) ? $matches[1] : "";
          $github_api['repo']   = ( isset( $matches[2] )) ? $matches[2] : "";
          $github_api['branch'] = ( isset( $matches[3] )) ? $matches[3] : "master";
          $github_api['path']   = ( isset( $matches[4] )) ? $matches[4] : "";
          $github_api['url']    = sprintf("https://github.com/%s/%s/archive/%s.tar.gz", $github_api['user'], $github_api['repo'], $github_api['branch']);
          break;
        }
      }
      if ( $this->download_url($github_api['url'], "$tmp_dir.tar.gz") === NULL) {
        $this->debug("\n Download ". $github_api['url'] ." has failed.");
        return NULL;
      } else {
        @mkdir($tmp_dir, 0777, TRUE);
        shell_exec("tar -zxf $tmp_dir.tar.gz --strip=1 -C $tmp_dir/ 2>&1");
        unlink("$tmp_dir.tar.gz");
      }
      $tmplsStor = array();
      $templates = $this->getTemplates($tmp_dir);
      $this->debug("\n Templates found in ". $github_api['url']);
      foreach ($templates as $template) {
        $storPath = sprintf("%s/%s", $Dest, str_replace($tmp_dir."/", "", $template['path']) );
        $tmplsStor[] = $storPath;
        if (! is_dir( dirname( $storPath ))) @mkdir( dirname( $storPath ), 0777, true);
        if ( is_file($storPath) ){
          if ( sha1_file( $template['path'] )  ===  sha1_file( $storPath )) {
            $this->debug("   Skipped: ".$template['prefix'].'/'.$template['name']);
            continue;
          } else {
            @copy($template['path'], $storPath);
            $this->debug("   Updated: ".$template['prefix'].'/'.$template['name']);
          }
        } else {
          @copy($template['path'], $storPath);
          $this->debug("   Added: ".$template['prefix'].'/'.$template['name']);
        }
      }
      $repotemplates = array_merge($repotemplates, $tmplsStor);
      $output[$url] = $tmplsStor;
      $this->removeDir($tmp_dir);
    }
    // Delete any templates not in the repos
    foreach ($this->listDir($Dest, "xml") as $arrLocalTemplate) {
      if (!in_array($arrLocalTemplate['path'], $repotemplates)) {
        unlink($arrLocalTemplate['path']);
        $this->debug("   Removed: ".$arrLocalTemplate['prefix'].'/'.$arrLocalTemplate['name']."\n");
        // Any other files left in this template folder? if not delete the folder too
        $files = array_diff(scandir(dirname($arrLocalTemplate['path'])), array('.', '..'));
        if (empty($files)) {
          rmdir(dirname($arrLocalTemplate['path']));
          $this->debug("   Removed: ".$arrLocalTemplate['prefix']);
        }
      }
    }
    return $output;
  }

  public function getTemplates($param) {
    global $DockerTemplates;
    return $DockerTemplates->getTemplates($param);
  }

  public function DownloadCommunityTemplates() {
    global $dockerManPaths, $infoFile, $DockerTemplates, $plugin;
    $output = array();
    if (! $download = $this->download_url($dockerManPaths['community-templates-url']) ){
      return false;
    }
    $Repos  = json_decode($download, TRUE);
    usort($Repos, $this->build_sorter('name'));

    shell_exec("rm -rf '".$dockerManPaths['templates-community']."'");
    $downloadURL = "/tmp/tmp-".mt_rand().".url";
    file_put_contents($downloadURL, implode(PHP_EOL,array_map(function($ar){return $ar['url'];},$Repos)) );
    if (! $templates = $this->downloadTemplates($dockerManPaths['templates-community']."/templates", $downloadURL)){
      return false;
    }
    unlink($downloadURL);
    foreach ($Repos as $Repo) {
      $tmpls = array();
      foreach ($templates[$Repo['url']] as $file) {
        if (is_file($file)){
          $doc              = new DOMDocument();
          @$doc->load($file);
          $o['Path']        = $file;
          $o['Repository']  = stripslashes($doc->getElementsByTagName( "Repository" )->item(0)->nodeValue);
          $o['Author']      = preg_replace("#/.*#", "", $o['Repository']);
          $o['Name']        = stripslashes($doc->getElementsByTagName( "Name" )->item(0)->nodeValue);
          if ( $doc->getElementsByTagName( "Overview" )->length ) {
            $o['Description'] = stripslashes($doc->getElementsByTagName( "Overview" )->item(0)->nodeValue);
            $o['Description'] = preg_replace('#\[([^\]]*)\]#', '<$1>', $o['Description']);
          } else {
            $o['Description'] = stripslashes($doc->getElementsByTagName( "Description" )->item(0)->nodeValue);
            $o['Description'] = preg_replace("#\[br\s*\]#i", "{}", $o['Description']);
            $o['Description'] = preg_replace("#\[b[\\\]*\s*\]#i", "||", $o['Description']);
            $o['Description'] = preg_replace('#\[([^\]]*)\]#', '<$1>', $o['Description']);
            $o['Description'] = preg_replace("#<span.*#si", "", $o['Description']);
            $o['Description'] = preg_replace("#<[^>]*>#i", '', $o['Description']);
            $o['Description'] = preg_replace("#"."{}"."#i", '<br>', $o['Description']);
            $o['Description'] = preg_replace("#"."\|\|"."#i", '<b>', $o['Description']);
            // $o['Description'] .= "<span style='font-style:italic;font-weight:bold;'>&#402;ix</span>";
          }
          $o['Support']     = ($doc->getElementsByTagName( "Support" )->length ) ? $doc->getElementsByTagName( "Support" )->item(0)->nodeValue : $Repo['forum'];
          $iconURL          = stripslashes($doc->getElementsByTagName( "Icon" )->item(0)->nodeValue);
          if ($iconURL) {
            preg_match_all("#:([\w]*$)#i", $o['Repository'], $matches);
            $tag      = isset($matches[1][0]) ? $matches[1][0] : "latest";
            $iconBase = sprintf("icons/%s-%s.png",preg_replace('%\/|\\\|:%', '-', $o['Repository']), $tag);
            $Icon     = sprintf("%s/%s", $dockerManPaths['templates-community'], $iconBase);
            if (!file_exists($Icon)) {
              if (! is_dir( dirname( $Icon ))) @mkdir( dirname( $Icon ), 0777, true);
              $this->debug("Downloading ".$iconURL);
              $this->download_url($iconURL, $Icon, TRUE);
            }
            $o['Icon'] = sprintf("%s/%s", "/state/plugins/${plugin}", $iconBase);
          } else {
            $o['Icon'] = "";
          }
          $tmpls[] = $o;
        }
      }
      $output[] = array('name'=>$Repo['name'], 'templates'=>$tmpls, 'url'=>$Repo['url']);
    }
    file_put_contents($dockerManPaths['community-templates-info'], json_encode($output, JSON_UNESCAPED_SLASHES));
    return true;
  }
}

function in_docker_repos($url) {
  global $docker_repos;
  return count(preg_grep("#$url#", $docker_repos)) ? TRUE : FALSE;
}

function highlight($text, $search) {
  return preg_replace('#'. preg_quote($text,'#') .'#si', '<span style="background-color:#FFFF66; color:#FF0000;font-weight:bold;">\\0</span>', $search);
}

switch ($_POST['action']) {
  case 'toggle_repo':
    $url = urldecode(($_POST['url']));
    $file = is_file($docker_repos) ? file($docker_repos,FILE_IGNORE_NEW_LINES) : array();
    if ( in_array($url, $file) ){ 
      $file = preg_grep("#${url}#i", $file, PREG_GREP_INVERT);
      $status = "disabled";
    } else {
      $file[] = $url;
      $status="enabled";
    }
    file_put_contents($docker_repos, implode(PHP_EOL, $file));
    $DockerTemplates = new DockerTemplates();
    $DockerTemplates->downloadTemplates();
    echo json_encode(array('status'=>$status));
  break;

  case 'get_content':
    $filter = isset($_POST['filter']) ? urldecode(($_POST['filter'])) : NULL;
    $docker_repos = is_file($docker_repos) ? file($docker_repos,FILE_IGNORE_NEW_LINES) : array();
    if (! file_exists($infoFile)) {
      $Community = new Community();
      if (! $Community->DownloadCommunityTemplates()) {
        echo "<div style='padding-top:79px;width:100%;text-align:center;'>
                <div style='background: linear-gradient(#E0E0E0,#C0C0C0);padding: 5px 20px 5px 6px;text-align:left;'><i class='fa fa-download fa-lg'></i> Info Download</div>
                <div style='text-align:center;font-style:italic;padding-top:10px;'>Download has failed.</div>
              </div>"; 
      }
    }
    $file = json_decode(@file_get_contents($infoFile),TRUE);
    $ct='';
    if (! is_array($file)) goto END;
    foreach ($file as $repo) {
      $img = in_docker_repos($repo['url']) ? "src='/plugins/$plugin/images/red.png' title='Click To Remove Repository'" : "src='/plugins/$plugin/images/green.png' title='Click To Add Repository'";
      $c = sprintf("<h2>%s <img %s style='width:48px;height:48px;cursor:pointer;' onclick='toggleRepo(this, \"%s\")'></h2>", 
             $repo['name'], 
             $img, 
             $repo['url']);
      $forum = $repo['forum'] ? $repo['forum'] : "";
      $c .= "<table class='tablesorter repositories'><thead><tr><th></th><th>Name</th><th>Author</th><th>Description</th></tr></thead><tbody>";
      $t = "";
      foreach ($repo['templates'] as $template) {
        if ($filter) {
          echo "<script>$('searchbox').val('$filter');$('#searchbox').focus().val($('#searchbox').val());</script>";
          $hasAll = 0;
          foreach (explode(" ", $filter) as $k) if($k) $searchTerms[] = $k;
          foreach ($searchTerms as $keyword) {
            if (! $keyword) continue;
            if ( preg_match("#$keyword#i", $template['Name']) || preg_match("#$keyword#i", $template['Author']) || preg_match("#$keyword#i", $template['Description']) ) $hasAll += 1;
            $template['Name'] = highlight($keyword, $template['Name'] );
            $template['Author'] = highlight($keyword, $template['Author'] );
            $template['Description'] = highlight($keyword, $template['Description']);
          }
          if ($hasAll < count($searchTerms) ) continue; 
        }
        $t .= sprintf("<tr><td><a href='/Docker/AddContainer?xmlTemplate=default:%s'  title='Click To Add Container' target='_blank'><img src='%s' style='width:48px;height:48px;'></a></td><td>%s%s</td><td>%s</td><td>%s</td></tr>", 
               $template['Path'], 
               ($template['Icon'] ? $template['Icon'] : "/plugins/$plugin/images/question.png"), 
               $template['Name'], 
               ( $template['Support'] ? "<div><a href='".$template['Support']."' target='_blank'>( Support )</a></div>" : "" ),
               $template['Author'], 
               $template['Description']);
      }
      if (strlen($t)) {
        $ct .= $c.$t."</tbody></table><div style='height:30px;'></div>";
      }
    }

    if (! strlen($ct) && $filter) {
      echo "<div style='padding-top:79px;width:100%;text-align:center;'>
              <div style='background: linear-gradient(#E0E0E0,#C0C0C0);padding: 5px 20px 5px 6px;text-align:left;'><i class='fa fa-search fa-lg'></i> Search Results</div>
              <div style='text-align:center;font-style:italic;padding-top:10px;'>Your search - <b>$filter</b> -  did not match any containers.</div>
              </div>
            </div>"; 
    } else {
      echo $ct;
    }
    END:
    echo "<script>
            var searching = false;
            el=$('.repositories tbody tr td:nth-child(2)');var max = 0;$(el).each(function(){max=Math.max($(this).textWidth(),max);});$(el).css('width',max+'px');;
            el=$('.repositories tbody tr td:nth-child(3)');var max = 0;$(el).each(function(){max=Math.max($(this).textWidth(),max);});$(el).css('width',max+'px');
            $('.repositories').tablesorter( {sortList: [[1,0]],headers:{0:{sorter:false}}});;
            $('.tablesorter tr:even').addClass('odd');
            searchBoxIcon('remove');
          </script>";

  break;
  case 'force_update':
    unlink($infoFile);
    echo json_encode(array('reload'=>TRUE));
  break;
}
?>