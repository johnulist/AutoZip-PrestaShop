<?php

/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to arossetti@users.noreply.github.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade AutoZip to newer
 * versions in the future. If you wish to customize AutoZip for your
 * needs please refer to https://github.com/arossetti/Prestashop-Module-AutoZip for more information.
 *
 *  @author    Antonio Rossetti <arossetti@users.noreply.github.com>
 *  @copyright Antonio Rossetti
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  
 */
if (!defined('_PS_VERSION_'))
    exit;

if (!defined('_AUTOZIP_TMP_')) {
    define('_AUTOZIP_TMP_', _PS_MODULE_DIR_.'autozip/tmp/');
    chdir(_AUTOZIP_TMP_);
}

class AutoZipCron {

    /**
     * cliExec
     * 
     * Wrapper to launch command line software. Manage STDOUT & alow virtual STDIN input 
     * Synchronise the output debug level on Prestashop's debug mode.
     * 
     * @param string $cmd
     * @param array $env
     * @param string $cwd
     * @param string $stdin
     * @param string $stdout
     * @param bool $throw_execption
     * @return boolean
     * @throws PrestaShopException
     */
    protected static function cliExec($cmd, $env = array(), $cwd = _AUTOZIP_TMP_, $stdin = null, &$stdout = null,
        $throw_execption = true) {

        if (_PS_DEBUG_PROFILING_)
            echo '# '.$cmd."\n";

        $pipes = array();

        $descriptors = array(
            0 => array("pipe", "r"), // stdin
            1 => array("pipe", "w"), // stdout
            2 => array("pipe", "w")  // stderr
        );

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($proc))
            throw new PrestaShopException('Unknown failure, maybe the php function "proc_open()" is not allowed');

        if ($stdin)
            fwrite($pipes[0], $stdin."\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $return = proc_close($proc);

        if ((int)$return) {

            if (_PS_MODE_DEV_) {
                $message = "\n".
                    '==== Command Line Error ===='."\n".
                    'Path        : '.$cwd."\n".
                    'Command     : '.$cmd."\n".
                    'Return code : '.(int)$return."\n".
                    ($stdin ? 'Input       : **** hidden for security reason ****'."\n" : '').
                    ($stdout ? 'Output      : '.$stdout : '').
                    ($stderr ? 'Error       : '.$stderr : '').
                    '============================'."\n";
            } else
                $message = "\n".$stderr;

            if ($throw_execption)
                throw new PrestaShopException($message);
            else
                return false;
        } else
            return true;
    }

    /**
     * checkCommandAvailability
     * 
     * @param string $cmds
     * @throws PrestaShopException
     */
    protected static function checkCommandAvailability($cmds) {

        $miss = array();
        foreach ($cmds as $cmd) {
            if (!self::cliExec('which '.$cmd, array(), _AUTOZIP_TMP_, null, $stdout, false)) {
                $miss[] = $cmd;
            }
        }
        if (count($miss)) {
            throw new PrestaShopException('"'.implode('","', $miss).
            '" CLI software(s) is(are) not installed on the system OR not available in the current ENV path.'
            ."\n".'Please Install software(s) or correct your ENV.');
        }
    }

    /**
     * checkCommonPrerequisities
     * 
     * @throws PrestaShopException
     */
    public static function checkCommonPrerequisities() {

        self::checkCommandAvailability(array('cp', 'rm', 'mv', 'zip'));

        if (!is_writable(_AUTOZIP_TMP_))
            throw new PrestaShopException('The directory "'._AUTOZIP_TMP_.
            '" must be accessible with write permission for the current user');

        if (!is_writable(_PS_DOWNLOAD_DIR_))
            throw new PrestaShopException('The directory "'._PS_DOWNLOAD_DIR_.
            '" must be accessible with write permission for the current user');
    }

    /**
     * gitDownload
     * 
     * @param AutoZipConfig $autozip
     * @return string
     */
    public static function gitDownload(AutoZipConfig $autozip) {

        self::checkCommandAvailability(array('git', 'sort', 'tail', 'find', 'xargs'));

        //Clear temporary space
        self::cliExec('rm -rf '._AUTOZIP_TMP_.'* '._AUTOZIP_TMP_.'.[a-z]*');
        self::cliExec('cp '._AUTOZIP_TMP_.'../index.php .');

        if (!$autozip->source_login && !$autozip->source_password)
            $source_url = $autozip->source_url;

        else {
            // we cannot pass the credentials thru "stdin", we have to include it in the url
            $parts = parse_url($autozip->source_url);
            $source_url = (isset($parts['scheme']) ? $parts['scheme'].'://' : '').
                ($autozip->source_login ? $autozip->source_login : //first we prefer use the dedicated field but
                    (isset($parts['user']) ? $parts['user'] : '')).//if only contained in the url, we will use it
                ($autozip->source_password ? ':'.$autozip->source_password : //idem login 
                    (isset($parts['pass']) ? ':'.$parts['pass'] : '')).
                (($autozip->source_login || $autozip->source_password) ? '@' : '').
                (isset($parts['host']) ? $parts['host'] : '').
                (isset($parts['port']) ? ':'.$parts['port'] : '').
                (isset($parts['path']) ? $parts['path'] : '').
                (isset($parts['query']) ? '?'.$parts['query'] : '').
                (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        }

        // Git Checkout
        self::cliExec('git clone "'.$source_url.'" download ',
            array('GIT_SSL_NO_VERIFY' => 'true', 'GIT_ASKPASS' => 'false'));

        // get last TAG name
        $last_tag = null;
        self::cliExec('git tag -l | sort -bt. -k1,1n -k2,2n -k3,3n -k4,4n -k5,5n -k6,6n -k7,7n -k8,8n | tail -n 1',
            array('GIT_SSL_NO_VERIFY' => 'true'), _AUTOZIP_TMP_.'download', null, $last_tag);

        // Switch to last TAG (if TAG exists ;)
        if ($last_tag)
            self::cliExec('git checkout -q tags/'.trim($last_tag), array('GIT_SSL_NO_VERIFY' => 'true'),
                _AUTOZIP_TMP_.'download');

        // Init all submodules (if the project have some)
        self::cliExec('git submodule init', array('GIT_SSL_NO_VERIFY' => 'true'), _AUTOZIP_TMP_.'download');
        self::cliExec('git submodule update', array('GIT_SSL_NO_VERIFY' => 'true'), _AUTOZIP_TMP_.'download');

        // Clean all git files.
        self::cliExec('find '._AUTOZIP_TMP_.'download -name ".git*" -print | xargs /bin/rm -rf');

        return trim($last_tag);
    }

    /**
     * svnDownload
     * 
     * We use the stdin pipe to avaoid password to be displayed on system"s process list.
     * 
     * @param AutoZipConfig $autozip
     * @return null
     */
    public static function svnDownload(AutoZipConfig $autozip) {

        self::checkCommandAvailability(array('svn', 'find', 'xargs'));

        //Clear temporary space
        self::cliExec('rm -rf '._AUTOZIP_TMP_.'* '._AUTOZIP_TMP_.'.[a-z]*');
        self::cliExec('cp '._AUTOZIP_TMP_.'../index.php .');

        self::cliExec('svn co "'.$autozip->source_url.'" download'.
            (($autozip->source_login || $autozip->source_password) ? ' --no-auth-cache' : '').
            ($autozip->source_login ? ' --username="'.$autozip->source_login.'"' : '').
            ($autozip->source_password ? ' --force-interactive' : ''), array(), _AUTOZIP_TMP_.'download',
            $autozip->source_password);

        // Clean all svn files.
        self::cliExec('find '._AUTOZIP_TMP_.'download -name ".svn" -print | xargs /bin/rm -rf');

        return null;
    }

    /**
     * wgetDownload
     * 
     * We use the stdin pipe to avaoid password to be displayed on system"s process list.
     * 
     * @param AutoZipConfig $autozip
     * @return null
     */
    public static function wgetDownload(AutoZipConfig $autozip) {

        self::checkCommandAvailability(array('wget', 'mkdir'));

        //Clear temporary space
        self::cliExec('rm -rf '._AUTOZIP_TMP_.'* '._AUTOZIP_TMP_.'.[a-z]*');
        self::cliExec('cp '._AUTOZIP_TMP_.'../index.php .');

        self::cliExec('mkdir -p '._AUTOZIP_TMP_.'download');
        self::cliExec('wget -nH -r '.$autozip->source_url.' '.
            ($autozip->source_login ? ' --user='.$autozip->source_login : '').' '.
            ($autozip->source_password ? ' --ask-password' : ''), array(), _AUTOZIP_TMP_.'download',
            $autozip->source_password);

        return null;
    }

    /**
     * generateZip
     * 
     * @param AutoZipConfig $autozip
     * @param string $version_number
     */
    public static function generateZip(AutoZipConfig $autozip, $version_number = null) {

        // Move the configured folder as source folder        
        if ($autozip->source_folder)
            self::cliExec('mv download/'.$autozip->source_folder.' source');
        else
            self::cliExec('mv download source');

        // Zip with or without root folder in the zip
        if ($autozip->zip_folder) {
            self::cliExec('mv source '.$autozip->zip_folder);
            self::cliExec('zip -qr autozip.zip '.$autozip->zip_folder);
        } else
            self::cliExec('zip -qr ../autozip.zip . ', array(), _AUTOZIP_TMP_.'source');


        if ($autozip->id_attachment) {

            // get the Attachement config
            $attachment = new Attachment($autozip->id_attachment);
            if (!$attachment->file)
                throw new PrestaShopException('The Attachement does not exists. Please update the autozip association');

            // Move the generated zip as the "regular" Attachement
            self::cliExec('mv autozip.zip '._PS_DOWNLOAD_DIR_.$attachment->file);

            if ($autozip->zip_basename)
                $attachment->file_name = $autozip->zip_basename.($version_number ? '-'.$version_number : '').'.zip';
            $attachment->mime = 'application/zip';
            $attachment->update();
        }else if ($autozip->id_product_download) {

            // get the Product Download config
            $product_download = new ProductDownload($autozip->id_product_download);
            if (!$product_download->id_product)
                throw new PrestaShopException('The product Download does not exists. Please update the autozip association');

            // Move the generated zip as the "regular" Product Download
            self::cliExec('mv autozip.zip '._PS_DOWNLOAD_DIR_.$product_download->filename);

            if ($autozip->zip_basename)
                $product_download->display_filename = $autozip->zip_basename.($version_number ? '-'.$version_number : '').'.zip';
            $product_download->date_add = date('Y-m-d H:i:s');

            //Prestashop dos not like the way he is himself storing an empty date (we do not change this field)
            if ($product_download->date_expiration === '0000-00-00 00:00:00')
                $product_download->date_expiration = null;

            $product_download->update();
        }
    }

    /**
     * updateVersionNumber
     * 
     * @param AutoZipConfig $autozip
     * @param type $version_number
     */
    public static function updateVersionNumber(AutoZipConfig $autozip, $version_number) {

        if (!$id_feature = (int)Configuration::get('AUTOZIP_ID_FEATURE'))
            return;

        $id_products = $autozip->getRelatedProductsIds();

        $id_langs = Language::getLanguages(false, false, true);

        foreach ($id_products as $id_product) {

            //Check if value already exists
            $id_feature_value = Db::getInstance()->getValue('SELECT DISTINCT fv.id_feature_value '
                .'FROM '._DB_PREFIX_.'feature_value fv, '._DB_PREFIX_.'feature_value_lang fvl '
                .'WHERE fv.id_feature_value = fvl.id_feature_value '
                .'AND fv.id_feature='.(int)$id_feature.' '
                .'AND fvl.value="'.$version_number.'" '
                .'AND fv.custom=1');

            // if not create
            if (!$id_feature_value) {
                $row = array(
                    'id_feature' => (int)$id_feature,
                    'custom' => true);
                Db::getInstance()->insert('feature_value', $row);
                $id_feature_value = Db::getInstance()->Insert_ID();

                // The version number will be stored in any language available
                foreach ($id_langs as $id_lang) {
                    $row = array(
                        'id_feature_value' => (int)$id_feature_value,
                        'id_lang' => (int)$id_lang,
                        'value' => pSQL($version_number));
                    Db::getInstance()->insert('feature_value_lang', $row);
                }
            }

            // Completly recreate the link between the product & feature/value, in any case, to keep unicity
            Db::getInstance()->delete('feature_product',
                'id_feature='.(int)$id_feature.' AND id_product='.(int)$id_product['id_product']);
            $row = array(
                'id_feature' => (int)$id_feature,
                'id_product' => (int)$id_product['id_product'],
                'id_feature_value' => (int)$id_feature_value);
            Db::getInstance()->insert('feature_product', $row, false, false, Db::REPLACE);
        }
    }

}
