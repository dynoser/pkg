<?php
namespace dynoser\webtools;

use dynoser\autoload\AutoLoader;

class Pkg
{
    public $updObj = null;

    public function canRemoveNS($nameSpace) {
        $chk = strtr($nameSpace, '\\', '/');
        foreach([
            'dynoser/autoload',
            'dynoser/webtools/Pkg',
        ] as $restrictedNS) {
            if ($chk === $restrictedNS) {
                return false;
            }
        }
        return true;
    }
    public function __construct($rootDir = '') {
        $myOwnDir = \strtr(__DIR__ , '\\', '/');

        // scan vendorDir
        $vendorDir = \defined('VENDOR_DIR') ? \constant('VENDOR_DIR') : '';
        if ($rootDir && !$vendorDir && \is_dir($rootDir . '/vendor') && !\defined('ROOT_DIR')) {
            \define('ROOT_DIR',  \strtr($rootDir, '\\', '/'));
            $nextChkDir = ROOT_DIR . '/vendor';
            \define('VENDOR_DIR', $nextChkDir);
        } else {
            $nextChkDir = $myOwnDir . '/vendor';
        }
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
            $autoLoadFile = \substr($myOwnDir, 0, -7) . 'autoload/autoload.php';
            if (\is_file($autoLoadFile)) {
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
                if (!defined('ROOT_DIR')) {
                    \define('ROOT_DIR', \dirname($myOwnDir));
                }
                foreach([
                    \dirname(ROOT_DIR) . '/nsmupdate/src',
                    \constant('VENDOR_DIR') . '/dynoser/nsmupdate/src',
                ] as $nsmupdDevDir) {
                    if (\is_dir($nsmupdDevDir)) {
                        foreach(\scandir($nsmupdDevDir) as $fileShort) {
                            if ($fileShort[0] === '.') continue;
                            include_once $nsmupdDevDir . '/' . $fileShort;
                        }
                        break;
                    }
                }
                
                require_once $autoLoadFile;
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
        echo <<<HTMLOPEN
<!DOCTYPE html>
<html>
<head>
    <title>Package Manager</title>
    <style>
        .header-container {
            display: flex;
            align-items: center;
        }
        .header-container h3 {
            margin-right: 10px;
        }
        .header-container a {
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="header-container">
        <h3>Package Manager</h3>
        [ <a href="/pkg/">Refresh</a> ]
        [ <a href="/pkg/?updateall=1">Update All</a> ]
        [ <a href="/pkg/?removecache=1">Remove Cache</a> ]
    </div>
HTMLOPEN;
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
                    echo "<li>$nameSpace:<ul>";
                    foreach($filesArr as $fileName => $targetArr) {
                        echo "  <li>$fileName => " . $targetArr[1] . "\n";
                    }
                    echo "</ul>\n";
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
            if ($this->canRemoveNS($removeNameSpace)) {
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
            } else {
                echo '<font color="red">Can not remove package "'. $removeNameSpace . '", its required for this script</font><hr>';
            }
        }

        echo '<h4><font color="green">All Installed packages:</font></h4>' 
            . "\n" 
            . '<table border="5" cellpadding="5" cellspacing="0" bordercolor="#eee">'
            . "\n";
        foreach($allNSKnownArr as $nameSpace => $filesArr) {
            if (\is_array($filesArr)) {
                $canRemove = $this->canRemoveNS($nameSpace);
                if ($canRemove && $removeNameSpace === $nameSpace) {
                    $canRemove = false;
                }
                echo '<tr><td>';
                if ($canRemove) {
                    echo '<a href="?remove=' . \urlencode($nameSpace) . '">del</a>';
                }
                echo '</td><td>';
                if ($removeNameSpace === $nameSpace) {
                    echo "<s>$nameSpace</s>";
                } else {
                    if ($canRemove) {
                        echo $nameSpace;
                    } else {
                        echo "<b>$nameSpace</b>";
                    }
                }
                echo '</td>';
                echo '<td>' . \count($filesArr) . "</td></tr>\n";
            }
        }
        echo "</table>\n";

        echo "\n<hr>\n";
        echo '<h4><font color="black">Availabled packages (not installed):</font></h4>' 
            . "\n" 
            . '<table border="5" cellpadding="5" cellspacing="0" bordercolor="#efe">'
            . "\n";
        //echo '<h4><font color="black">Availabled packages (not installed):</font></h4>'. "\n<table>\n";
        foreach($allNSKnownArr as $nameSpace => $filesArr) {
            if (!\is_array($filesArr)) {
                echo '<tr><td><a href="?install=' . \urlencode($nameSpace) . '">+</a></td><td>' . $nameSpace . "</td></tr>\n";
            }
        }
        echo "</table>";

        echo "<pre>";
        $changesArr = $this->updObj->lookForDifferences();
        if ($changesArr) {
            //print_r($changesArr);
            $nsChangedArr = [];
            echo "<hr/>Can update:<br/>";
            foreach($changesArr['modifiedFilesArr'] as $fileFullName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpaceArr) {
                        foreach($nameSpaceArr as $nameSpace) {
                            $nsChangedArr[$nameSpace][$fileFullName] = "UPD #" . $hashHex;
                        }
                    }
                }
            }
            foreach($changesArr['notFoundFilesMapArr'] as $fileFullName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpaceArr) {
                        foreach($nameSpaceArr as $nameSpace) {
                            $nsChangedArr[$nameSpace][$fileFullName] = "DEL #" . $hashHex;
                        }
                    }
                }
            }
            echo "<ul>";
            foreach($nsChangedArr as $nameSpace => $filesArr) {
                echo "<li><h4><font color=\"green\">$nameSpace</font><h4><ul>\n";
                foreach($filesArr as $fileFullName => $hashHex) {
                    echo "<li>$fileFullName => $hashHex \n";
                }
                echo "</ul>\n";
            }
            echo "</ul>";
            echo '<H2><a href="?updateall=1">Update ALL</a></H2>';
        }
        
        echo <<<HTMLCLOSE
    <hr/><h3><a href="/pkg/">(Refresh)</a></h3>
</body>
</html>
HTMLCLOSE;
    }
}

if (!\defined('DYNO_FILE')) {
    // called from web
    $myObj = new Pkg(\getcwd());
    $myObj->run();
}
