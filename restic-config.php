<?php

$config = Array(
    "append_only" => false,         // enable append only mode
    "max_size" => 0,                // the maximum size of the repository in bytes. Set it to 0 to be unlimited.
    "path" => "./restic",           // data directory (default "./restic")
    "private_repos" => false        // users can only access their private repo. Requires http auth.
);
