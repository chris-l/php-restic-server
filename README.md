# php-restic-server

php-restic-server is a PHP implementation of restic's [REST backend API](http://restic.readthedocs.io/en/latest/100_references.html#rest-backend).  It provides a way to backup data to a remote PHP server, using [restic](https://github.com/restic/restic) backup client via the [rest: URL](http://restic.readthedocs.io/en/latest/030_preparing_a_new_repo.html#rest-server).

**NOTE: If possible, use the [official rest-server](https://github.com/restic/rest-server) instead of this project.** Use php-restic-server only for those cases when the server where you want to make your backups only allows you to run PHP scripts.

## Requirements

* PHP 5.3 or better.
* The server needs to allow PHP scripts to create, edit, and delete files and directories.
* The server should allow you to redirect every request in the path where php-restic-server is installed to be handled by the `restic-index.php` script. That can be done with an `.htaccess` file in the case of apache.
* The server should allow the upload and storage of files of the size of the Blobs (some servers put limits on the sizes allowed). According to the [documentation](https://restic.readthedocs.io/en/latest/100_references.html#backups-and-deduplication), Blobs are from 512 KiB to 8 MiB in size. However, in my tests, I have found Blobs as large as 8.7 MiB. Therefore, the server should allow uploads and storage of files with a size slightly bigger, like 10 MiB at least.
* To use the private repos mode, authentication is required. And its highly recommended that the server has an SSL cert to serve requests via HTTPS. Otherwise, the auth password could easily be sniffed by a third party.

## Installation

Create a directory inside the document root of your server and copy the files `restic-index.php`, `restic-server.php` and `restic-config.php` there. In this same directory, create a `restic` directory where your repos will reside (you can change the directory that will store your repos by editing the line `"path" => "./restic"` on `restic-config.php`).

If the server is Apache, then create an `.htaccess` file to redirect every request to `restic-index.php`.

The content of the file may have to be adjusted for your particular server, but it will be something similar to this:

```conf
RewriteEngine on
RewriteRule ^.*$ restic-index.php [L]
```

If the server is going to use authentication, it will be necessary to also add the required lines for that.

If you are using nginx instead, the same result can be achieved with `try_files` using a line similar to this:

```conf
try_files $uri $uri/ /restic-index.php?$args;
```

Read the nginx documentation to find more about it.

## Usage

Edit the file `restic-config.php` to change the options.

(They use the same names and are used mostly the same way as the [original rest-server](https://github.com/restic/rest-server#usage), but not all the options are present)


| property        | type     | default value     | description |
| --------------- | -------- | ----------------- | ----------- |
| `append_only`   | `bool`   | `false`           | enable append only mode |
| `max_size`      | `int`    | `0`               | the maximum size of the repository in bytes. Set it to 0 to be unlimited. |
| `path`          | `string` | `"./restic"`      | data directory |
| `private_repos` | `bool`   | `false`           | users can only access their private repo. Requires http auth. |

For using the private repos mode, http auth is required. And it's _highly recommended_ to use HTTPS, to prevent the auth password being passed over an unencrypted connection.

## License

MIT

