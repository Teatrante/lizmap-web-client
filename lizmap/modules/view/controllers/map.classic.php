<?php
/**
* Displays a full featured map based on one Qgis project.
* @package   lizmap
* @subpackage view
* @author    3liz
* @copyright 2011 3liz
* @link      http://3liz.com
* @license    Mozilla Public License : http://www.mozilla.org/MPL/
*/

class mapCtrl extends jController {

  /**
  * Load the map page for the given project.
  * @param string $repository Name of the repository.
  * @param string $project Name of the project.
  * @return Page with map and content for the chose Qgis project.
  */
  function index() {

    if ($this->param('theme')) {
      jApp::config()->theme = $this->param('theme');
    }
    $rep = $this->getResponse('htmlmap');
    $rep->addJSLink(jUrl::get('view~translate:index'));
    $ok = true;

    // Get the project
    $project = filter_var($this->param('project'), FILTER_SANITIZE_STRING);

    // Get repository data
    $repository = $this->param('repository');

    // Get lizmapRepository class
    // if repository not found get the default
    $lrep = null;
    $lser = lizmap::getServices();
    if ( !$repository ){
      $lrep = lizmap::getRepository($lser->defaultRepository);
    } else {
      $lrep = lizmap::getRepository($repository);
    }

    if(!$lrep or !jacl2::check('lizmap.repositories.view', $lrep->getKey())){
      $rep = $this->getResponse('redirect');
      $rep->action = 'view~default:index';
      jMessage::add(jLocale::get('view~default.repository.access.denied'), 'error');
      return $rep;
    }

    // We must redirect to default repository project list if no project given
    if(!$project){
      jMessage::add('The parameter project is mandatory !', 'error');
      $ok = false;
    }

    // Get lizmapProject class
    if($ok){
      $lproj = lizmap::getProject($lrep->getKey().'~'.$project);
      if(!$lproj){
        jMessage::add('The lizmapProject '.strtoupper($project).' does not exist !', 'error');
        $ok = false;
      }
    }

    // Redirect if project is hidden (lizmap plugin option)
    if($ok){
      $pOptions = $lproj->getOptions();
      if (
          property_exists($pOptions,'hideProject')
          && $pOptions->hideProject == 'True'
      ){
        jMessage::add(jLocale::get('view~default.project.access.denied'), 'error');
        $ok = false;
      }
    }


    // Redirect if error encountered
    if(!$ok){
      $rep = $this->getResponse('redirect');
      $rep->params = array('repository'=>$lrep->getKey());
      $rep->action = 'view~default:index';
      return $rep;
    }

    // Add js link if google is needed
    if ( $lproj->needsGoogle() ) {
      $googleKey = $lproj->getGoogleKey();
      if ( $googleKey != '' )
        $rep->addJSLink('https://maps.google.com/maps/api/js?v=3.5&sensor=false&key='.$googleKey);
      else
        $rep->addJSLink('https://maps.google.com/maps/api/js?v=3.5&sensor=false');
    }

    // Add the jForms js
    $bp = jApp::config()->urlengine['basePath'];
    $rep->addJSLink($bp.'jelix/js/jforms_light.js');
    $rep->addJSLink($bp.'js/bootstrapErrorDecoratorHtml.js');

    // Pass some configuration options to the web page through javascript var
    $lizUrls = array(
      "params" => array('repository'=>$repository, 'project'=>$project),
      "config" => jUrl::get('lizmap~service:getProjectConfig'),
      "wms" => jUrl::get('lizmap~service:index'),
      "media" => jUrl::get('view~media:getMedia'),
      "nominatim" => jUrl::get('lizmap~osm:nominatim'),
      "ign" => jUrl::get('lizmap~ign:address'),
      "edition" => jUrl::get('lizmap~edition:getFeature'),
      "permalink" => jUrl::getFull('view~map:index')
    );

    // Get optionnal WMS public url list
    $lser = lizmap::getServices();
    if($lser->wmsPublicUrlList){
        $publicUrlList = $lser->wmsPublicUrlList;
        function f($x) {
            return jUrl::getFull('lizmap~service:index', array(), 0, trim($x));
        }
        $pul = array_map('f', explode(',', $publicUrlList));
        $lizUrls['publicUrlList'] = $pul;
    }

    if(jacl2::check('lizmap.admin.repositories.delete'))
      $lizUrls['removeCache'] = jUrl::get('admin~config:removeLayerCache');

    $rep->addJSCode("var lizUrls = ".json_encode($lizUrls).";");
    $rep->addStyle('#map','background-color:'.$lproj->getCanvasColor().';');

    // Get the WMS information
    $wmsInfo = $lproj->getWMSInformation();
    // Set page title from projet title
    if( $wmsInfo['WMSServiceTitle'] != '' )
      $rep->title = $wmsInfo['WMSServiceTitle'];
    else
      $rep->title = $repository.' - '.$project;

    // Add Timemanager
    if( $lproj->hasTimemanagerLayers() ) {
        $rep->addJSLink($bp.'js/date.js');
        $rep->addJSLink($bp.'js/timemanager.js');
    }

    // Assign variables to template
    $assign = array_merge(array(
      'repositoryLabel'=>$lrep->getData('label'),
      'repository'=>$lrep->getKey(),
      'project'=>$project,
    ), $wmsInfo);


    // WMS GetCapabilities Url
    $wmsGetCapabilitiesUrl = jacl2::check(
      'lizmap.tools.displayGetCapabilitiesLinks',
      $lrep->getKey()
    );
    if ( $wmsGetCapabilitiesUrl ) {
      $wmsGetCapabilitiesUrl = $lproj->getData('wmsGetCapabilitiesUrl');
    }
    $assign['wmsGetCapabilitiesUrl'] = $wmsGetCapabilitiesUrl;


    // Replace default theme by theme found in
    // the repository folder media/themes/default/
    $useCustomTheme = 0;
    $customCssFiles = array();
    if ( $lrep->getData('allowUserDefinedThemes') ) {
      $repositoryPath = $lrep->getPath();
      $cssArray = array('main', 'map', 'media');
      $themeArray = array('default', $project);
      foreach ( $cssArray as $k ) {
        foreach ( $themeArray as $theme ) {
          $cssRelPath = 'media/themes/'.$theme.'/css/'.$k.'.css';
          $cssPath = $lrep->getPath().'/'.$cssRelPath;
          if (file_exists($cssPath) ){
            $cssUrl = jUrl::get(
              'view~media:getCssFile',
              array(
                'repository'=>$lrep->getKey(),
                'project'=>$project,
                'path'=>$cssRelPath
              )
            );
            $customCssFiles[] = $cssUrl;
            $useCustomTheme = 1;
          }
        }
      }
    }
    $assign['useCustomTheme'] = $useCustomTheme;
    $assign['customCssFiles'] = $customCssFiles;
    $rep->body->assign($assign);

    // Log
    $eventParams = array(
        'key' => 'viewmap',
        'content' => '',
        'repository' => $lrep->getKey(),
        'project' => $project
    );
    jEvent::notify('LizLogItem', $eventParams);

    return $rep;
  }


}
