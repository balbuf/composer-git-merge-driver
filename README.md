# Composer JSON Git Merge Driver

The Composer JSON Git Merge Driver provides a mechanism to more effectively merge
`composer.json` and `composer.lock` files that have been modified simultaneously
in separate branches / development histories. The [custom git merge driver][merge driver]
is invoked when the composer JSON files require a merge more complex than a
simple "fast forward."

## How it Works

The merge driver replaces git's standard merge algorithm only for composer's JSON files:
instead of analyzing the files for changed lines, the JSON is parsed and the actual properties
and values are compared for changes. As such, the merge driver is able to more gracefully
handle most new, updated, and removed dependencies in your composer files. A merge conflict
is only triggered when the version constraint, locked version number, or presence / absence
of the same dependency has been modified in multiple development histories involved in the
merge.

For instance, if a certain dependency is updated in one branch and removed in another,
a merge conflict is triggered because it is unclear which change for that dependency is
desired following the merge. However, if a new, different dependency is appended to the
`require` section in both branches, the merge driver will understand that both should
be kept, whereas the standard git merge driver would trigger a merge conflict because
the same line has been edited in both branches.

More generally, all object data structures are merged gracefully and recursively, meaning
other composer configuration properties in the composer JSON files (e.g. `extra` and
`config`) will be merged properly as well&mdash;whenever changes are not ambiguous. In fact,
the merge driver will work on any JSON file whose outermost data structure is an object.

### Composer Lock Handling

For the the lock file in particular, special handling is performed to minimize the potential
for merge conflicts. For example, the locked dependency data is converted into an object
for the purposes of merging and converted back to the proper data structure when the merge
is complete. As well, the `content-hash` property is excluded from the merge, as the very
nature of this property guarantees that two differing branches would have conflicting values.
The content hash is used by composer to efficiently recognize that the `composer.json` file
has changed, so the actual value is not necessarily important after a merge has occured.
As a workaround for not being able to generate an accurate hash, the merge driver sets its
own unique value for the hash to signal to composer that a change has occurred; the value is
a simple message instructing you to run `composer update --lock` so composer can regenerate
the real hash!

[merge driver]: https://git-scm.com/docs/gitattributes#_defining_a_custom_merge_driver

## Installation

The merge driver can be installed globally or per repo and configured and activated globally or per repo,
i.e. you can install the driver globally but only activate it on certain projects.

### 1. Install the merge driver on your system.

Use composer to install the driver globally:

```sh
$ composer global require balbuf/composer-git-merge-driver
```

or just in a particular repo:

```sh
$ composer require --dev balbuf/composer-git-merge-driver
```

Note that if you are installing the driver via the latter (per repo) method, this will be added
to the repo's `composer.json` and thus will be installed as a dependency for all users of the repo.

### 2. Configure the merge driver with git.

The driver is made available for use by informing git of its existence via a [git config][git config] file:

```
[merge "composer_json"]
    name = composer JSON file merge driver
    driver = composer-git-merge-driver %O %A %B %L %P
    recursive = binary
```

To open the git config file for editing in your default command line text editor:

```sh
$ git config -e
```

By default, this allows you to edit the config file for the current repo, meaning the driver
is only installed locally to the repo. To edit the global config file for your user, append the
`--global` flag; to edit the system-wide config file, append the `--system` flag.

Copy and paste the block above into the config file and save it. This example assumes that your `$PATH`
includes [Composer's vendor bin][vendor bin] path (by default, `~/.composer/vendor/bin` for global
installation or `./vendor/bin` for repo installation). If not, be sure to update your `$PATH` or
add the appropriate path to the binary on the `driver` line.

For more information about git config files, refer to the [git documentation][git config].

[git config]: https://git-scm.com/docs/git-config
[vendor bin]: https://getcomposer.org/doc/articles/vendor-binaries.md#can-vendor-binaries-be-installed-somewhere-other-than-vendor-bin-

### 3. Activate the merge driver.

Finally, the merge driver must be activated for `composer.json` and `composer.lock` files via
a [git attributes][git attributes] file:

```
composer.json merge=composer_json
composer.lock merge=composer_json
```

To activate only for a specific repo, edit the `.git/info/attributes` file inside of the repo.
To activate globally for your user, edit the `~/.gitattributes` file; to activate system-wide,
edit the `$(prefix)/etc/gitattributes` file (e.g. `/usr/local/etc/gitattributes`). Copy and
paste the block above into the config file and save it. In some cases the file may not yet exist,
in which case you can simply create the file at the aforementioned path.

For more information about git attributes files, refer to the [git documentation][git attributes].

[git attributes]: https://git-scm.com/docs/gitattributes

## Usage

The merge driver is automatically invoked any time a `composer.json` and/or `composer.lock` file
must be merged in a repo where the driver is activated. If there are any merge conflicts within
the files, the standard git merge conflict procedure kicks in: you will be alerted of which file(s)
contain conflicts, and conflicts are denoted via the standard conflict markers (e.g. `<<<<<<<`).

If there are no conflicts and the merge can be applied cleanly, the merge completes as normal.
However, a changed lock file results in a non-standard `content-hash` value (whether there are
conflicts or not). While there should be no harm in leaving the hash value as-is, it's best to
let composer regenerate the hash. To do so, simply run:

```sh
$ composer update --lock
```

The value of the hash actually informs you to take this action, but if the merge completes with
no conflicts, you may not even notice the message. When there are conflicts, this step should
be completed after fixing the conflicts but before finishing the merge. If there were no conflicts
and the merge completed, you can avoid creating an additional commit by amending the merge commit:

```sh
$ composer update --lock
$ git add composer.lock
$ git commit --amend --no-edit
```

## Additional Information

### Requirements

The merge driver is written in PHP and requires at least version 5.4.

### Known Limitations

- The merge driver parses and regenerates the JSON to complete the merge. As such, some formatting
may be lost or altered in the process. For the `composer.json` file, the driver attempts to detect
the indentation preference of the file in the working tree and replicate that indentation when
the JSON is regenerated, which should minimize formatting changes. However, some whitespace style
cannot be preserved, e.g. fewer line breaks or more whitespace. While the interpreted contents
of the file will not be compromised, the formatting could result in extraneous changes to the file
as a result of the merge. (In keeping with the behavior of composer, the `composer.lock` file is
regenerated by the default behavior of PHP's `json_encode` function and is consistently indented.)

- When a merge conflict occurs where the last property of an object is removed, accepting the removed
version will result in invalid syntax due to a trailing comma on the preceding property. Care should
be taken to verify the syntax of the overall file and manually update accordingly.

### Acknowledgements

Thanks to [@christian-blades-cb](https://gist.github.com/christian-blades-cb/f75ec813f15393498b6c)
and [@jphass](https://gist.github.com/jphaas/ad7823b3469aac112a52) for the inspiration and examples.
