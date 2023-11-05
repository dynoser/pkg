<?php
namespace dynoser\webtools;

class Pkg
{
    public $updObj = null;

    public function __construct($rootDir = '') {
        // scan vendorDir
        $vendorDir = \defined('VENDOR_DIR') ? \constant('VENDOR_DIR') : '';
        $myOwnDir = \strtr(__DIR__ , '\\', '/');
        $nextChkDir = $myOwnDir . '/vendor';
        do {
            $chkDir = $nextChkDir;
            if (\is_dir($chkDir)) {
                $vendorDir = $chkDir;
                break;
            }
            $nextChkDir = \rtrim(\dirname($chkDir, 2), '/\\') . '/vendor';
        } while (\strlen($nextChkDir) < \strlen($chkDir));
        if (\substr($myOwnDir, -7) === 'pkg/pkg') {
            // developer mode ON
            $chkFile = \substr($myOwnDir, 0, -7) . 'autoload/autoload.php';
            if (\is_file($chkFile)) {
                if (!\defined('VENDOR_DIR')) {
                    $vendorDir = $myOwnDir . '/vendor';
                    \define('VENDOR_DIR', $vendorDir);
                    if (!\is_dir($vendorDir) && !\mkdir($vendorDir)) {
                        die("Can't create vendorDir = $vendorDir");
                    }
                    if (!\defined('STORAGE_DIR')) {
                        \define('STORAGE_DIR', $vendorDir . '/cache');
                    }
                }
                \define('ROOT_DIR', \dirname($myOwnDir));
                $nsmupdDevDir = \dirname(ROOT_DIR) . '/nsmupdate/src';
                if (\is_dir($nsmupdDevDir)) {
                    foreach(\scandir($nsmupdDevDir) as $fileShort) {
                        if ($fileShort[0] === '.') continue;
                        include_once $nsmupdDevDir . '/' . $fileShort;
                    }
                }
                
                require_once $chkFile;
            }
        }

        // scan autoloader
        if ($vendorDir) {
            foreach([
                $vendorDir . '/autoload.php',
                $vendorDir . '/dynoser/autoload/autoload.php',
                ''
            ] as $chkFile) {
                if (\defined('DYNO_FILE')) {
                    break;
                }
                if ($chkFile && \is_file($chkFile)) {
                    // load autoloader
                    require_once $chkFile;
                }
            }
        }
        if (empty($chkFile) || !\defined('DYNO_FILE')) {
            die("Dynoser-autoloader not found, cannot continue (since this script is a dynoser-autoloader module)");
        }

        $this->updObj = new \dynoser\nsmupdate\UpdateByNSMaps(false, false);
    }
    
    public function msg($msg) {
        $this->updObj->msg($msg);
    }

    public function run() {
        if (!empty($_REQUEST['install'])) {
            $instClass = $_REQUEST['install'];
            $classFullName = \trim(\strtr($instClass, '/', '\\'), '\\ ');

            echo "<pre>Try install class: '$classFullName' ... ";
            
            try {
                $res = AutoLoader::autoLoad($classFullName, false);
                if ($res) {
                    echo "OK\n";
                    echo "Class file: $res\n";
                } else {
                    echo "Not found\n";
                }
    
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                echo "\\Exception: $error \n";
            } finally {
                echo "</pre>";
            }
        } elseif (!empty($_REQUEST['remove'])) {
            $removeClass = $_REQUEST['remove'];
            $classFullName = \trim(\strtr($removeClass, '/', '\\'), '\\ ');
            echo "<pre>Try remove class: $removeClass ... (break)\n";            
        }
        if (!empty($_REQUEST['updateall'])) {
            echo "<pre>";
            //$this->updObj->removeCache();
            $this->msg("Try update all ...\n");
            $updatedResultsArr = $this->updObj->update();
            print_r($updatedResultsArr);
        } else {
            $allNSKnownArr = $this->updObj->getAllNSKnownArr();
            echo "All Installed packages:\n<table>\n";
            foreach($allNSKnownArr as $nameSpace => $filesArr) {
                if (\is_array($filesArr)) {
                    echo '<tr><td><a href="?remove=' . \urlencode($nameSpace) . '">+</a></td>'
                            .'<td>' . $nameSpace . "</td>"
                            .'<td>' . \count($filesArr) . "</td></tr>\n";
                }
            }
            echo "</table>\n";
            
            echo "\n<hr>\nAll availabled packages (not installed):\n<table>\n";
            foreach($allNSKnownArr as $nameSpace => $filesArr) {
                if (!\is_array($filesArr)) {
                    echo '<tr><td><a href="?install=' . \urlencode($nameSpace) . '">+</a></td><td>' . $nameSpace . "</td></tr>\n";
                }
            }
            echo "</table>";

            echo "<pre>";
            $changesArr = $this->updObj->lookForDifferences();
            print_r($changesArr);

        }
    }
}

if (!\defined('DYNO_FILE')) {
    // called from web
    $myObj = new Pkg();
    $myObj->run();
}
