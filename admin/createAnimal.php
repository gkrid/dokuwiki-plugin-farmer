<?php
/**
 * DokuWiki Plugin farmer (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <grosse@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class admin_plugin_farmer_createAnimal extends DokuWiki_Admin_Plugin {

    private $preloadPHPMissing = false;
    private $errorMessages = array();

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort() {
        return 42;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly() {
        return true;
    }

    public function createNewAnimal($name, $subdomain, $serverSetup, $adminPassword) {
        //DOKU_FARMDIR
        /** @var helper_plugin_farmer $helper */
        $helper = plugin_load('helper','farmer');

        if ($serverSetup === 'subdomain') {
            $animaldir = DOKU_FARMDIR . $subdomain;
        } elseif ($serverSetup === 'htaccess') {
            $animaldir = DOKU_FARMDIR . $name;
        } else {
            throw new Exception('invalid value for $serverSetup');
        }
        //todo: abort if animaldir already exists

        $helper->io_copyDir(DOKU_FARMDIR . '_animal', $animaldir);

        $cryptAdminPassword = auth_cryptPassword($adminPassword);
        $usersAuth = file_get_contents($animaldir . '/conf/users.auth.php');
        $usersAuth = str_replace('$1$cce258b2$U9o5nK0z4MhTfB5QlKF23/', $cryptAdminPassword, $usersAuth);
        io_saveFile($animaldir . '/conf/users.auth.php',$usersAuth);

        $confFile = file_get_contents($animaldir . '/conf/local.php');
        $confFile = str_replace('Animal Wiki Title', $name, $confFile);
        io_saveFile($animaldir . '/conf/local.php', $confFile);

    }

    public function createPreloadPHP($animalpath) {
        // todo: check if animalpath is writable
        io_makeFileDir($animalpath . '/foo');

        // todo: move template download to its own function
        file_put_contents($animalpath . '/_animal.zip',fopen('https://www.dokuwiki.org/_media/dokuwiki_farm_animal.zip','r'));
        $zip = new ZipArchive();
        $zip->open($animalpath.'/_animal.zip');
        $zip->extractTo($animalpath);
        $zip->close();
        unlink($animalpath.'/_animal.zip');


        $content = "<?php\n";
        $content .= "if(!defined('DOKU_FARMDIR')) define('DOKU_FARMDIR', '$animalpath');\n";
        $content .= "include(fullpath(dirname(__FILE__)).'/farm.php');\n";

        io_saveFile(DOKU_INC . 'inc/preload.php',$content);
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle() {
        // Is preload.php already enabled?
        if (!file_exists(DOKU_INC . 'inc/preload.php')) {
            $this->preloadPHPMissing = true;
            if (isset($_REQUEST['farmdir'])) {
                $this->createPreloadPHP($_REQUEST['farmdir']);

                global $ID;
                $get = $_GET;
                if(isset($get['id'])) unset($get['id']);
                $self = wl($ID, $get, false, '&');
                send_redirect($self);
            } else {
                dbg($_REQUEST);
            }
        } else {
            dbg($_REQUEST);
            if (isset($_REQUEST['farmer__submit'])) {
                if (!isset($_REQUEST['serversetup'])) {
                    $this->errorMessages['serversetup'] = 'Choose either a subdomain setup and enter a valid subdomain or choose a htaccess setup.';
                }
                if (empty($this->errorMessages)) {
                    $this->createNewAnimal($_REQUEST['animalname'], $_REQUEST['animalsubdomain'], $_REQUEST['serversetup'], $_REQUEST['adminPassword']);
                    // todo: message: animal successful created
                }
            }
        }
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html() {

        if ($this->preloadPHPMissing) {
            $form = new \dokuwiki\Form\Form();
            $form->addClass('plugin_farmer');
            $form->addFieldsetOpen('create a new preload.php');
            $form->addTagOpen('div class="form-group"');
            $form->addElement(new \dokuwiki\Form\LabelElement('farm dir'))->attr('for', 'plugin__farmer__farmdir');
            $form->addTextInput('farmdir')->addClass('form-control')->attr('placeholder','farm dir')->id('plugin__farmer__farmdir');
            $form->addTagClose('div');

            $form->addButton('farmer__submit','Submit')->attr('type','submit')->val('newPerload');

            $form->addButton('farmer__reset','Reset')->attr('type','reset');
            $form->addFieldsetClose();

            echo $form->toHTML();
        } else {
            $form = new \dokuwiki\Form\Form();
            $form->addClass('plugin_farmer');
            $form->addFieldsetOpen('new animal configuration');
            $form->addTextInput('animalname','animal name')->addClass('block edit')->attr('placeholder','animal name');
            $form->addTag('br');

            $form->addTagOpen('fieldset');
            $form->addHTML('<legend>animal administrator</legend>');
            $form->addRadioButton('adminsetup','import all users of the master wiki to the new animal')->val('importUsers')->addClass('block');
            $form->addRadioButton('adminsetup', 'Set the current user as admin')->val('currentAdmin')->addClass('block');
            $form->addRadioButton('adminsetup', 'Create new admin user "admin"')->val('newAdmin')->addClass('block');
            $form->addPasswordInput('adminPassword',$this->getLang('admin password'))->addClass('block edit')->attr('placeholder','Password for admin account');
            $form->addTagClose('fieldset');

            $form->addTagOpen('fieldset');
            $form->addHTML('<legend>server configuration</legend>');
            $form->addRadioButton('serversetup', 'htaccess setup')->val('htaccess')->attr('type','radio')->addClass('block');
            $form->addRadioButton('serversetup', 'Subdomain setup')->val('subdomain')->attr('type','radio')->addClass('block');
            $form->addTextInput('animalsubdomain','animal subdomain')->addClass('block edit')->attr('placeholder','animal subdomain');
            $form->addTagClose('fieldset');

            $form->addButton('farmer__submit','Submit')->attr('type','submit')->val('newAnimal');
            $form->addButton('farmer__reset','Reset')->attr('type','reset');
            $form->addFieldsetClose();

            for ($position = 0; $position < $form->elementCount(); ++$position) {
                if ($form->getElementAt($position) instanceof dokuwiki\Form\TagCloseElement) {
                    continue;
                }
                if ($form->getElementAt($position)->attr('name') == '') continue;
                if (!isset($this->errorMessages[$form->getElementAt($position)->attr('name')])) continue;
                $form->getElementAt($position)->addClass('error');
                $form->addTagOpen('div',$position+1)->addClass('error');
                $form->addHTML($this->errorMessages['serversetup'],$position+2);
                $form->addTagClose('div',$position+3);
            }

            echo $form->toHTML();
        }

    }
}

// vim:ts=4:sw=4:et:
