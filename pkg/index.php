<?php
namespace dynoser\webtools;

use dynoser\autoload\AutoLoader;

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
        if (!empty($_REQUEST['removecache'])) {
            echo "<pre>Remove cache:\n";
            $this->updObj->removeCache();
            echo "</pre>";
        }
        if (!empty($_REQUEST['install'])) {
            $instClass = $_REQUEST['install'];
            
            if (\strpos($instClass, '/')) {
                // namespace + \Test
                $classFullName = \trim(\strtr($instClass, '/', '\\'), '\\ ') . '\\Test';
            } else {
                $classFullName = $instClass;
            }

            echo "<pre>Try install class: '$classFullName' ... ";
            
            try {
                $res = AutoLoader::autoLoad($classFullName, false);
                if ($res) {
                    echo "OK\n";
                    echo "Class file: $res\n";
                } else {
                    if (\substr($classFullName, -4) === 'Test') {
                        echo "(unknown result)\n";
                    } else {
                        echo "Not found\n";
                    }
                }
    
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                echo "\\Exception: $error \n";
            } finally {
                echo "</pre>";
            }
        }
        if (!empty($_REQUEST['updateall'])) {
            echo "<pre>";
            //$this->updObj->removeCache();
            $this->msg("Try update all ...\n");
            $updatedResultsArr = $this->updObj->update();
            echo "</pre>";
            echo "<h2>Update results:</h2>\n";
            if ($updatedResultsArr) {
                echo "<ul>\n";
                foreach($updatedResultsArr as $nameSpace => $filesArr) {
                    echo "<li>$nameSpace:<ol>";
                    foreach($filesArr as $fileName => $targetArr) {
                        echo "  <li>$fileName => " . $targetArr[1] . "\n";
                    }
                    echo "</ol>\n";
                }
                echo "</ul>\n";
            } else {
                echo "<h4>No changes</h4>";
            }
            echo "</pre>";
        }
        $allNSKnownArr = $this->updObj->getAllNSKnownArr();

         if (empty($_REQUEST['remove'])) {
            $removeNameSpace = '';
         } else {
            $removeNameSpace = $_REQUEST['remove'];
            echo "<pre>Try remove package: $removeNameSpace... ";
            $filesArr = $allNSKnownArr[$removeNameSpace] ?? null;
            if (!\is_array($filesArr)) {
                echo "Not installed\n";
            } else {
                echo "Files:\n";
                foreach($filesArr as $fileFull) {
                    if (\unlink($fileFull)) {
                        echo " - $fileFull removed\n";
                    }
                }
                echo "Package '$removeNameSpace' removed\n";
            }
            echo "</pre>\n";
            $allNSKnownArr = $this->updObj->getAllNSKnownArr();
        }

        echo "All Installed packages:\n<table>\n";
        foreach($allNSKnownArr as $nameSpace => $filesArr) {
            if (\is_array($filesArr)) {
                echo '<tr><td>[<a href="?remove=' . \urlencode($nameSpace) . '">del</a>]</td>';
                echo '<td>';
                if ($removeNameSpace === $nameSpace) {
                    echo "<s>$nameSpace</s>";
                } else {
                    echo $nameSpace;
                }
                echo '</td>';
                echo '<td>' . \count($filesArr) . "</td></tr>\n";
            }
        }
        echo "</table>\n";

        echo "\n<hr>\nAll availabled packages (not installed):\n<table>\n";
        foreach($allNSKnownArr as $nameSpace => $filesArr) {
            if (!\is_array($filesArr)) {
                echo '<tr><td>[<a href="?install=' . \urlencode($nameSpace) . '">install</a>]</td><td>' . $nameSpace . "</td></tr>\n";
            }
        }
        echo "</table>";

        echo "<pre>";
        $changesArr = $this->updObj->lookForDifferences();
        print_r($changesArr);
        if ($changesArr) {
            $nsChangedArr = [];
            echo "<hr>\Changes:";
            foreach($changesArr['modifiedFilesArr'] as $fileName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpace) {
                        $nsChangedArr[$nameSpace][$fileName] = "UPD #" . $hashHex;
                    }
                }
            }
            foreach($changesArr['notFoundFilesMapArr'] as $fileName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpace) {
                        $nsChangedArr[$nameSpace][$fileName] = "DEL #" . $hashHex;
                    }
                }
            }
            print_r($nsChangedArr);
            
            echo '<H2><a href="?updateall=1">Update ALL</a></H2>';
        }
    }
}

if (!\defined('DYNO_FILE')) {
    // called from web
    $myObj = new Pkg();
    $myObj->run();
}
