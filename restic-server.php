<?php
class Restic
{
    public $validTypes = Array("data", "index", "keys", "locks", "snapshots", "config");
    public $mimeTypeAPIV1 = "application/vnd.x.restic.rest.v1";
    public $mimeTypeAPIV2 = "application/vnd.x.restic.rest.v2";
    private $append_only = false;
    private $block_size = 8192;
    private $basePath = "restic";
    private $currentSize = 0;
    private $maxRepoSize = 0;
    public $private_repos = false;


    private function __construct($opts)
    {
        if (array_key_exists("path", $opts)) {
            $this->basePath = $opts["path"];
        }
        if (array_key_exists("private_repos", $opts)) {
            $this->private_repos = $opts["private_repos"];
        }
        if (array_key_exists("append_only", $opts)) {
            $this->append_only = $opts["append_only"];
        }
        if (array_key_exists("block_size", $opts)) {
            $this->block_size = $opts["block_size"];
        }
        if (array_key_exists("max_size", $opts)) {
            $this->maxRepoSize = $opts["max_size"];
        }
    }
    public static function Instance($opts = Array())
    {
        static $inst = null;
        if ($inst === null) {
            $inst = new Restic($opts);
        }
        return $inst;
    }
    public function sendStatus($status)
    {
        switch ($status) {
        case 206:
            header($_SERVER["SERVER_PROTOCOL"] . " 206 Partial Content");
            break;
        case 400:
            header($_SERVER["SERVER_PROTOCOL"] . " 400 Bad Request");
            break;
        case 401:
            header($_SERVER["SERVER_PROTOCOL"] . " 401 Unauthorized");
            break;
        case 403:
            header($_SERVER["SERVER_PROTOCOL"] . " 403 Forbidden");
            break;
        case 404:
            header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
            break;
        case 411;
            header($_SERVER["SERVER_PROTOCOL"] . " 411 Length Required");
            break;
        case 413:
            header($_SERVER["SERVER_PROTOCOL"] . " 413 Request Entity Too Large");
            break;
        case 416:
            header($_SERVER["SERVER_PROTOCOL"] . " 416 Requested Range Not Satisfiable");
            break;
        case 500:
            header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
            break;
        default:
            header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
        }
    }

    private function tallySize($path, $firstrun = false)
    {
        $size = 0;
        $items = $firstrun
            ? $this->validTypes
            : scandir($path);
        foreach($items as $i) {
            if ($i === "." || $i === "..") {
                continue;
            }
            $fullpath = $this->pathResolve($path, $i);
            if (is_dir($fullpath)) {
                $size += $this->tallySize($fullpath);
            } else {
                $st = stat($fullpath);
                $size += $st["size"];
            }
        }
        return $size;
    }

    private function pathResolve()
    {
        $sep = DIRECTORY_SEPARATOR;
        $working_dir = getcwd();
        $out = Array();

        foreach(func_get_args() as $p) {
            if ($p === null || $p === "") {
                continue;
            }
            if ($p[0] === $sep) {
                $working_dir = $p;
                continue;
            }
            $working_dir .= $sep . $p;
        }

        $regex = "/[" . str_replace("/", "\/", $sep) . "]+/";
        $working_dir = preg_replace($regex, $sep, $working_dir);
        if ($working_dir === $sep) {
            return $sep;
        }

        foreach (explode($sep, rtrim($working_dir, $sep)) as $p) {
            if ($p === ".") {
                continue;
            }
            if ($p === "..") {
                array_pop($out);
                continue;
            }
            array_push($out, $p);
        }

        return implode($sep, $out);
    }

    private function isHashed($dir)
    {
        return $dir === "data";
    }

    private function serveContent($file)
    {
        $offset = 0;
        $mime = (function_exists("mime_content_type"))
            ? mime_content_type($file)
            : "application/octet-stream";
        $fullsize = filesize($file);
        $length = $fullsize;
        $is_range = false;

        if (array_key_exists("HTTP_RANGE", $_SERVER)) {
            if (substr($_SERVER["HTTP_RANGE"], 0, 6) !== "bytes=") {
                $this->sendStatus(416); // not satisfiable
                header("Content-Range: bytes */" . $fullsize);
                exit;
            }

            $parts = explode("-", substr($_SERVER["HTTP_RANGE"], 6));

            if (empty($parts[0])) {
                $offset = 0;
            } else {
                $offset = intval($parts[0]);
            }

            if (!($offset === 0 && empty($parts[1]))) {
                if (empty($parts[1])) {
                    $end = $fullsize - 1;
                } else {
                    $end = intval($parts[1]);
                }
                $length = ($end - $offset) + 1;

                if ($length < 0 || ($offset + $length) > $fullsize) {
                    $this->sendStatus(416); // not satisfiable
                    header("Content-Range: bytes */" . $fullsize);
                    exit;
                }
                $is_range = true;
            }
        }


        if ($is_range) {
            $this->sendStatus(206); //partial content
            header("Content-Range: bytes " . $offset . "-" . ($offset + $length - 1) . "/" . $fullsize);
        }
        header("Content-Type: " . $mime);
        header("Content-Length: " . $length);
        $file = fopen($file, "r");
        fseek($file, $offset);

        // Use the default block size, unless the requested length is smaller
        $block = $length < $this->block_size
            ? $length
            : $this->block_size;

        $max = round($length / $block);
        $extra = $length > $block
            ? $length % $block
            : 0;

        for ($c = 0; $c < $max; $c++) {
            $data = fread($file, $block);
            if (!$data) {
                exit;
            }
            print($data);
        }

        // Serve any extra bytes
        if ($extra > 0) {
            $data = fread($file, $extra);
            if (!$data) {
                exit;
            }
            print($data);
        }

        fclose($file);
    }

    public function listBlobs($repo = "", $type = "") {
        if (func_num_args() === 1) {
            $type = func_get_arg(0);
            $repo = ".";
        }
        switch ($_SERVER["HTTP_ACCEPT"]) {
        case $this->mimeTypeAPIV2:
            $this->listBlobsV2($repo, $type);
            break;
        default:
            $this->listBlobsV1($repo, $type);
        }
        return;
    }

    public function listBlobsV1($repo_name, $type)
    {
        $names = Array();
        $path = $this->pathResolve($this->basePath, $repo_name, $type);
        $items = scandir($path);
        foreach($items as $i) {
            if ($i === "." || $i === "..") {
                continue;
            }
            if ($this->isHashed($type)) {
                $subitems = scandir($this->pathResolve($path, $i));
                foreach($subitems as $f) {
                    if ($f === "." || $f === "..") {
                        continue;
                    }
                    array_push($names, $f);
                }
            } else {
                array_push($names, $i);
            }
        }
        header("Content-Type: " . $this->mimeTypeAPIV1);
        echo json_encode($names);
    }
    public function listBlobsV2($repo_name, $type)
    {
        if (func_num_args() === 1) {
            $type = func_get_arg(0);
            $repo_name = ".";
        }
        $names = Array();
        $path = $this->pathResolve($this->basePath, $repo_name, $type);
        $items = scandir($path);
        foreach($items as $i) {
            if ($i === "." || $i === "..") {
                continue;
            }
            if ($this->isHashed($type)) {
                $subpath = $this->pathResolve($path, $i);
                $subitems = scandir($subpath);
                foreach($subitems as $f) {
                    if ($f === "." || $f === "..") {
                        continue;
                    }
                    $st = stat($this->pathResolve($subpath, $f));
                    array_push($names, Array("name" => $f, "size" => $st["size"]));
                }
            } else {
                $st = stat($this->pathResolve($path, $i));
                array_push($names, Array("name" => $i, "size" => $st["size"]));
            }
        }
        header("Content-Type: " . $this->mimeTypeAPIV2);
        echo json_encode($names);
    }

    public function saveBlob($repo_name, $type, $name = "")
    {
        if (func_num_args() === 2) {
            $name = func_get_arg(1);
            $type = func_get_arg(0);
            $repo_name = ".";
        }

        if ($this->maxRepoSize != 0) {
            // We never update currentSize after this, because the server will execute
            // an instance of the script per request anyway. The stat cache helps with the speed.
            $this->currentSize = $this->tallySize($this->pathResolve($this->basePath, $repo_name), true);

            if (array_key_exists("CONTENT_LENGTH", $_SERVER) && $_SERVER["CONTENT_LENGTH"] != "") {
                $contentLen = intval($_SERVER["CONTENT_LENGTH"]);
                if (($this->currentSize + $contentLen) > $this->maxRepoSize) {
                    $this->sendStatus(413); // payload too large
                    exit;
                }
            } else {
                $this->sendStatus(411); // length required
                exit;
            }
        }

        if ($this->isHashed($type)) {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, substr($name, 0, 2), $name);
        } else {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, $name);
        }
        $tf = fopen($path, "x");
        if ($tf === false) {
            $this->sendStatus(403); // forbidden
            exit;
        }
        $body = fopen("php://input", "r");
        stream_copy_to_stream($body, $tf);

        if (fclose($tf) === false || fclose($body) === false) {
            $this->sendStatus(500); // internal error
            exit;
        }
        header("Content-Type:");
    }

    public function checkBlob($repo_name, $type, $name = "")
    {
        if (func_num_args() === 2) {
            $name = func_get_arg(1);
            $type = func_get_arg(0);
            $repo_name = ".";
        }
        if ($this->isHashed($type)) {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, substr($name, 0, 2), $name);
        } else {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, $name);
        }

        if (!file_exists($path)) {
            $this->sendStatus(404); //not found
            exit;
        }
        $st = stat($path);
        if (!$st) {
            $this->sendStatus(500); // internal error
            exit;
        }

        header("Content-Type:");
        header("Content-Length: " . $st["size"]);
        exit;
    }

    public function getBlob($repo_name, $type, $name = "")
    {
        if (func_num_args() === 2) {
            $name = func_get_arg(1);
            $type = func_get_arg(0);
            $repo_name = ".";
        }
        if ($this->isHashed($type)) {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, substr($name, 0, 2), $name);
        } else {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, $name);
        }

        if (!file_exists($path)) {
            $this->sendStatus(404); //not found
            exit;
        }
        $st = stat($path);
        if (!$st) {
            $this->sendStatus(500); // internal error
            exit;
        }

        $this->serveContent($path);
        exit;
    }

    public function deleteBlob($repo_name, $type, $name = "")
    {
        if (func_num_args() === 2) {
            $name = func_get_arg(1);
            $type = func_get_arg(0);
            $repo_name = ".";
        }

        if ($this->append_only && $type != "locks") {
            $this->sendStatus(403); // forbidden
            exit;
        }

        if ($this->isHashed($type)) {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, substr($name, 0, 2), $name);
        } else {
            $path = $this->pathResolve($this->basePath, $repo_name, $type, $name);
        }

        if (!file_exists($path)) {
            $this->sendStatus(404); //not found
            exit;
        }
        if (!unlink($path)) {
            $this->sendStatus(500); // internal error
            exit;
        }
        header("Content-Type:");
    }

    public function checkConfig($repo_name = ".")
    {
        $cfg = $this->pathResolve($this->basePath, $repo_name, "config");

        if (!file_exists($cfg)) {
            $this->sendStatus(404); //not found
            exit;
        }
        $st = stat($cfg);
        if (!$st) {
            $this->sendStatus(500); // internal error
            exit;
        }

        header("Content-Type:");
        header("Content-Length: " . $st["size"]);
        exit;
    }

    public function getConfig($repo_name = ".")
    {
        $cfg = $this->pathResolve($this->basePath, $repo_name, "config");

        if (!file_exists($cfg)) {
            $this->sendStatus(404); //not found;
            exit;
        }
        $this->serveContent($cfg);
    }

    public function deleteConfig($repo_name = ".")
    {
        $cfg = $this->pathResolve($this->basePath, $repo_name, "config");

        if ($this->append_only) {
            $this->sendStatus(403); // forbidden
            exit;
        }

        if (!file_exists($cfg)) {
            $this->sendStatus(404); //not found
            exit;
        }
        if (!unlink($cfg)) {
            $this->sendStatus(500); // internal error
            exit;
        }
        header("Content-Type:");
    }

    // saveConfig allows for a config to be saved.
    public function saveConfig($repo_name = ".")
    {
        $cfg = $this->pathResolve($this->basePath, $repo_name, "config");

        $f = fopen($cfg, "x");
        if ($f === false) {
            $this->sendStatus(403); // forbidden
            exit;
        }

        $body = fopen("php://input", "r");
        $err = stream_copy_to_stream($body, $f);

        if ($err === false || fclose($f) === false || fclose($body) === false) {
            $this->sendStatus(500); // internal error
            exit;
        }
    }

    // createRepo creates repository directories.
    public function createRepo($repo_name = ".")
    {
        $repo = $this->pathResolve($this->basePath, $repo_name);
        if (!array_key_exists("create", $_GET) || $_GET["create"] != "true") {
            $this->sendStatus(400); // bad request
            exit;
        }

        if ($repo_name !== "." && !mkdir($repo, 0700, true)) {
            $this->sendStatus(500); // internal error
            exit;
        }

        foreach ($this->validTypes as $d) {
            if ($d == "config") {
                continue;
            }

            if (!mkdir($this->pathResolve($repo, $d), 0700)) {
                $this->sendStatus(500); // internal error
                exit;
            }
        }

        for ($i = 0; $i <= 255; $i++) {
            if (!mkdir($this->pathResolve($repo, "data", sprintf("%02x", $i)), 0700)) {
                $this->sendStatus(500); // internal error
                exit;
            }
        }
    }
}
