<?php
namespace dynoser\webtools;

use dynoser\autoload\AutoLoader;
use Jfcherng\Diff\DiffHelper;
use Jfcherng\Diff\Renderer\RendererConstant;

class Pkg
{
    public static $passArr = [
        //username => sha256(password)
        'test'     => 'da8be698d805f74da997ac7ad381b5aaa76384c9e27f78ae5d5688be95e39d92',  //Nhkb
        'max'      => '593d0b69b5445e9fc54a5a71e083cb9e7c111b3a84bc2823ba288a0ae8f37e08',
    ];
    public $passIsOk = false;
    public $updObj = null;
    
    public $rootDir = '';
    
    public array $remoteNsMapURLs = [];
    
    public bool $compareON = true;

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

        $devMode = false;
        if (\substr($myOwnDir, -7) === 'pkg/pkg') {
            if (\substr($myOwnDir, -22) === 'vendor/dynoser/pkg/pkg') {
                // composer mode ON
                \define('ROOT_DIR', \substr($myOwnDir, 0, -23));
                \define('VENDOR_DIR', \constant('ROOT_DIR') . '/vendor');
                $autoLoadFile = \constant('VENDOR_DIR') . '/dynoser/autoload/autoload.php';
            } else {
                // developer mode ON
                $devMode = true;
                $autoLoadFile = \dirname($myOwnDir, 2) . '/autoload/autoload.php';
            }
    
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

                // pre-load dev.nsmapupdate pkg without autoloader
                $nsmUpSrcArr = [];
                if ($devMode) {
                    $nsmUpSrcArr[] = \dirname($myOwnDir, 2) . '/nsmupdate/src';
                }
                $nsmUpSrcArr[] = \dirname(\constant('ROOT_DIR')) . '/nsmupdate/src';
                $nsmUpSrcArr[] = \constant('VENDOR_DIR') . '/dynoser/nsmupdate/src';
                foreach($nsmUpSrcArr as $nsmupdDevDir) {
                    if (\is_dir($nsmupdDevDir)) {
                        foreach(\scandir($nsmupdDevDir) as $fileShort) {
                            if ($fileShort[0] === '.') continue;
                            include_once $nsmupdDevDir . '/' . $fileShort;
                        }
                        break;
                    }
                }
                
                require_once $autoLoadFile;
            } else {
                $devMode = false;
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
        $this->rootDir = \defined('ROOT_DIR') ? ROOT_DIR : \dirname($vendorDir);
        if (empty($chkFile) || !\defined('DYNO_FILE')) {
            die("Dynoser-autoloader not found, cannot continue (since this script is a dynoser-autoloader module)");
        }
        
        // check comparer
        if ($this->compareON) {
            $this->compareON = AutoLoader::classExists('Jfcherng/Diff/Differ', true, false);
        }

        $this->updObj = new \dynoser\nsmupdate\UpdateByNSMaps(false, false);
        
        $this->remoteNsMapURLs = $this->updObj->getRemoteNSMapURLs();
    }
    
    public function msg($msg) {
        $this->updObj->msg($msg);
    }
    
    public function openPage() {
        header("Content-Type: text/html; charset=UTF-8");
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
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
    }
            
    public function closePage() {
        echo '</body></html>';
    }

    public function run() {
        // is it Compare link?
        if (!empty($_REQUEST['compare']) && !empty($_REQUEST['hsfile'])) {
            $compareShortFile = self::base64Udecode($_REQUEST['compare']);
            $hashSigFileFull = $_REQUEST['hsfile'];
            if ($hashSigFileFull && $compareShortFile) {
                $this->compareShow($compareShortFile, $hashSigFileFull, $hashHex);
            }
        }
        
        // is it Remove cache?
        if (!empty($_REQUEST['removecache'])) {
            echo "<pre>Remove cache:\n";
            $this->updObj->removeCache();
            echo "</pre>";
        }
        
        //  is it ADD or REMOVE links in $this->nsMapURLsArr ?
        $this->editNSMAPLinkRequests();

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

        $updateByHashesOnlyArr = [];
        if (!empty($_POST['update'])) {
            if (isset($_POST['update']) && isset($_POST['selectedFiles'])) {
                $selectedFiles = $_POST['selectedFiles'];
                if ($selectedFiles && \is_array($selectedFiles)) {
                    $this->msg("<pre>Selected for update:\n<ol>");
                    foreach($selectedFiles as $hashHex) {
                        $this->msg("<li> $hashHex");
                        $updateByHashesOnlyArr[$hashHex] = true;
                    }
                    $this->msg("</ol></pre>");
                }
            }
        }
        if (!empty($_REQUEST['updateall']) || $updateByHashesOnlyArr) {        
            echo "<pre>";
            if (empty($updateByHashesOnlyArr) || !\is_array($updateByHashesOnlyArr)) {
                die("UpdateAll temporary disabled, please go back and select each file for update");
            }
            //$this->updObj->removeCache();
            $this->msg("Try update all ...\n");
            $updatedResultsArr = $this->updObj->update(
                [], //$onlyNSarr = [],
                [], //$skipNSarr = [],
                [], //$doNotUpdateFilesArr = [],
                $updateByHashesOnlyArr
            );
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

        echo '<h4><font color="green">Installed packages:</font></h4>' 
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
            . '<table border="5" cellpadding="5" cellspacing="0" bordercolor="#fff">'
            . "\n";
        //echo '<h4><font color="black">Availabled packages (not installed):</font></h4>'. "\n<table>\n";
        foreach($allNSKnownArr as $nameSpace => $filesArr) {
            if (!\is_array($filesArr)) {
                echo '<tr><td><a href="?install=' . \urlencode($nameSpace) . '">ins</a></td><td>' . $nameSpace . "</td></tr>\n";
            }
        }
        echo "</table>";

        echo "<pre>";
        $changesArr = $this->updObj->lookForDifferences();
        if ($changesArr) {
            //print_r($changesArr);
            $nsChangedArr = [];
            echo '<hr/><h4><font color="black">Updates:</font></h4>'. "\n" ;
            foreach($changesArr['modifiedFilesArr'] as $fileFullName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpaceArr) {
                        foreach($nameSpaceArr as $nameSpace) {
                            $nsChangedArr[$nameSpace][$fileFullName] = "UPD " . $hashHex;
                        }
                    }
                }
            }
            foreach($changesArr['notFoundFilesMapArr'] as $fileFullName => $verArr) {
                foreach($verArr as $hashHex => $lenNSarr) {
                    foreach($lenNSarr as $len => $nameSpaceArr) {
                        foreach($nameSpaceArr as $nameSpace) {
                            $nsChangedArr[$nameSpace][$fileFullName] = "ADD " . $hashHex;
                        }
                    }
                }
            }
            echo '<form action="" method="post">';
            echo "<ul>";
            $rootLen = \strlen($this->rootDir);
            foreach($nsChangedArr as $nameSpace => $filesArr) {
                echo "<li><h4><font color=\"green\">$nameSpace</font></h4><ul>\n";
                foreach($filesArr as $fileFullName => $actHashHex) {
                    $act = \explode(" ", $actHashHex);
                    $shortFileName = \substr($fileFullName, $rootLen);
                    $hashHex = $act[1];
                    $act = $act[0];
                    if ($this->compareON) {
                        $compareLink = '?compare=' . self::base64Uencode($shortFileName) . '&hsfile=' . $hashHex;
                    }
                    echo "<li>$act <input type='checkbox' name='selectedFiles[]' value='$hashHex'>";
                    if ($this->compareON) {
                        echo '<a href="' . $compareLink . '">';
                    }
                    echo $shortFileName;
                    if ($this->compareON) {
                        echo '</a>';
                    }
                    echo "=> $hashHex</li>";
                    echo "\n";
                }
                echo "</ul>\n";
            }
            echo "</ul>";
            echo "<input type='submit' name='update' value='Update'>";
            echo "</form>";
            echo '<H3><a href="?updateall=1">Update ALL</a></H3>';
        }
    }
    
    public function compareShow($shortFile, $hashSigFileFull, $hashHex) {
        if (!$this->compareON) {
            die("Comparer not available");
        }
        // calculate full file name from short
        $fullFile = $this->rootDir . $shortFile;
        if (!is_file($fullFile)) {
            die("Not found: $fullFile");
        }
        $oldStr = \file_get_contents($fullFile);
        
        $hs = new HashSigBase();
        $result = $hs->getFilesByHashSig(
            $hashSigFileFull,
            null, //$saveToDir = null,
            null, //$baseURLs = null,
            true, //bool $doNotSaveFiles = false,
            true, //bool $doNotOverWrite = false,
            false, //bool $zipOnlyMode = false,
            [$shortFile] //$onlyTheseFilesArr = null
        );
        $newStr = $result[$shortFile];
        
        // renderer class name:
        //     Text renderers: Context, JsonText, Unified
        //     HTML renderers: Combined, Inline, JsonHtml, SideBySide
        $rendererName = 'SideBySide';

        // the Diff class options
        $differOptions = [
            // show how many neighbor lines
            // Differ::CONTEXT_ALL can be used to show the whole file
            'context' => 3,
            // ignore case difference
            'ignoreCase' => false,
            // ignore line ending difference
            'ignoreLineEnding' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => false,
            // if the input sequence is too long, it will just gives up (especially for char-level diff)
            'lengthLimit' => 2000,
        ];

        // the renderer class options
        $rendererOptions = [
            // how detailed the rendered HTML in-line diff is? (none, line, word, char)
            'detailLevel' => 'line',
            // renderer language: eng, cht, chs, jpn, ...
            // or an array which has the same keys with a language file
            // check the "Custom Language" section in the readme for more advanced usage
            'language' => 'eng',
            // show line numbers in HTML renderers
            'lineNumbers' => true,
            // show a separator between different diff hunks in HTML renderers
            'separateBlock' => true,
            // show the (table) header
            'showHeader' => true,
            // the frontend HTML could use CSS "white-space: pre;" to visualize consecutive whitespaces
            // but if you want to visualize them in the backend with "&nbsp;", you can set this to true
            'spacesToNbsp' => false,
            // HTML renderer tab width (negative = do not convert into spaces)
            'tabSize' => 4,
            // this option is currently only for the Combined renderer.
            // it determines whether a replace-type block should be merged or not
            // depending on the content changed ratio, which values between 0 and 1.
            'mergeThreshold' => 0.8,
            // this option is currently only for the Unified and the Context renderers.
            // RendererConstant::CLI_COLOR_AUTO = colorize the output if possible (default)
            // RendererConstant::CLI_COLOR_ENABLE = force to colorize the output
            // RendererConstant::CLI_COLOR_DISABLE = force not to colorize the output
            'cliColorization' => RendererConstant::CLI_COLOR_AUTO,
            // this option is currently only for the Json renderer.
            // internally, ops (tags) are all int type but this is not good for human reading.
            // set this to "true" to convert them into string form before outputting.
            'outputTagAsString' => false,
            // this option is currently only for the Json renderer.
            // it controls how the output JSON is formatted.
            // see available options on https://www.php.net/manual/en/function.json-encode.php
            'jsonEncodeFlags' => \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            // this option is currently effective when the "detailLevel" is "word"
            // characters listed in this array can be used to make diff segments into a whole
            // for example, making "<del>good</del>-<del>looking</del>" into "<del>good-looking</del>"
            // this should bring better readability but set this to empty array if you do not want it
            'wordGlues' => [' ', '-'],
            // change this value to a string as the returned diff if the two input strings are identical
            'resultForIdenticals' => null,
            // extra HTML classes added to the DOM of the diff container
            'wrapperClasses' => ['diff-wrapper'],
        ];
        //$htmlRenderer = RendererFactory::make('Inline', $rendererOptions);

        // one-line simply compare two files
        //$result = DiffHelper::calculateFiles($oldFile, $newFile, $rendererName, $differOptions, $rendererOptions);
        $result   = DiffHelper::calculate($oldStr, $newStr, $rendererName, $differOptions, $rendererOptions);
        echo $result;
    }
    
    /**
     * Encode a string using the base64url encoding scheme
     * 
     * @param string $str
     * @return string
     */
    public static function base64Uencode($str) {
        $enc = \base64_encode($str);
        return \rtrim(\strtr($enc, '+/', '-_'), '=');
    }
    
    /**
     * Decode a base64url encoded string
     * 
     * @param string $str
     * @return string
     */
    public static function base64Udecode($str) {
        return \base64_decode(\strtr($str, '-_', '+/'));
    }

    // --- BEGIN OF NSMAP LINKS EDITOR ---    
    public function addNSMAPLink(string $newNSMAPlink) {
        $this->remoteNsMapURLs[] = $newNSMAPlink;
        $this->saveNewNSMapURLs();
    }

    public function deleteNSMAPLink($index) {
        if (isset($this->remoteNsMapURLs[$index])) {
            unset($this->remoteNsMapURLs[$index]);
            $this->saveNewNSMapURLs();
        }
    }
    
    public function saveNewNSMapURLs() {
        if ($this->remoteNsMapURLs) {
            $this->updObj->setRemoteNSMapURLs($this->remoteNsMapURLs);
        }
    }

    public function editNSMAPLinkRequests() {
        if (isset($_REQUEST['add_nsmap_link']) && !empty($_REQUEST['new_nsmap_link'])) {
            $newNSMAPLink = $_REQUEST['new_nsmap_link'];
            $this->addNSMAPLink($newNSMAPLink);
        }

        if (isset($_POST['delete_nsmap_link']) && isset($_POST['selected_nsmap_link'])) {
            $indexToDelete = (int) $_POST['selected_nsmap_link'];
            $this->deleteNSMAPLink($indexToDelete);
        }
    }
    public function showNSMAPlinksEditor() {
        echo '<table bgcolor="#fff" cellpadding="5" cellspacing="0" border="1">';
        echo "<tr><td>\n";
        echo '<table bgcolor="#fff" cellpadding="0" cellspacing="0" border="0">';
        echo "<tr><td><b>NsMap links:</b></td><td></td></tr>\n";
        foreach ($this->remoteNsMapURLs as $key => $link) {
            echo '<form method="post" action=""><tr>';
            echo '<td>' . $link . '</td>';
            echo '<td align="right"><input type="hidden" name="selected_nsmap_link" value="' . $key . '">';
            echo '<input type="submit" name="delete_nsmap_link" value="Del"></td>';
            echo "</tr>";
            echo "</form>\n";
        }
        echo "<tr>";
        echo '<form method="post" action="">';
        echo '<td><input type="text" size="100" name="new_nsmap_link" placeholder="Enter Link .hashsig.zip" required></td>';
        echo '<td><input type="submit" name="add_nsmap_link" value="Add"></td>';
        echo "</form></tr>\n";
        echo "</table>\n";
        echo "</td></tr></table><br/>\n";
    }
    //  ---- END OF NSMAP LINKS EDITOR ---
    
    public function authCheck() {
        if (!$this->passIsOk && !empty($_COOKIE['username']) && !empty($_COOKIE['password'])) {
            $username = $_COOKIE['username'];
            $password = $_COOKIE['password'];
            if ($username && $password && !empty(self::$passArr[$username])) {
                $this->passIsOk = (\hash('sha256', $password) === self::$passArr[$username]);
            }
        }
        if (!$this->passIsOk && !empty($GLOBALS['argv'][2])) {
            global $argv;
            $username = $argv[1];
            $password = $argv[2];
            if ($username && $password && !empty(self::$passArr[$username])) {
                $this->passIsOk = (\hash('sha256', $password) === self::$passArr[$username]);
            }            
        }
        if (!$this->passIsOk && !empty($_SERVER["REQUEST_METHOD"]) && ($_SERVER["REQUEST_METHOD"] === "POST")) {
            $username = $_POST['username'] ?? '';
            $enteredPassword = $_POST['password'] ?? '';
            if ($username && $enteredPassword && !empty(self::$passArr[$username])) {
                $enteredPasswordHash = \hash('sha256', $enteredPassword);
                $this->passIsOk = ($enteredPasswordHash === self::$passArr[$username]);
                if ($this->passIsOk) {
                    \setcookie('username', $username, time() + 3600, "/");
                    \setcookie('password', $enteredPassword, time() + 3600, "/");
                }
            }
        }
        if (!$this->passIsOk) {
            echo <<<PASSFORM
<!DOCTYPE html>
<html>
<head>
    <title>Password check</title>
</head>
<body>
    <form method="POST" action="">
        <label for="username">username:</label>
        <input type="text" name="username" id="username">
        <label for="password">password:</label>
        <input type="password" name="password" id="password">
        <button type="submit">Check</button>
    </form>
</body>
</html>
PASSFORM;
           die;
        }
    }
}

if (!\defined('DYNO_FILE')) {
    // called from web
    $myObj = new Pkg(\getcwd());
    $myObj->authCheck();
    $myObj->openPage();
    $myObj->run();
    $myObj->showNSMAPlinksEditor();
    $myObj->closePage();
}
