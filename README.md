# RealUsernames

RealUsernames is a mediawiki extension that shows user real names (almost) everywhere within a wiki site.

## Installation

- Current version requires `MediaWiki >=1.31 <1.36`
- [Download and unzip it](https://github.com/stronk7/RealUsernames/releases) (or clone this git repo).
- Copy the folder as `RealUsernames` within the `extensions`directory of your mediawiki site.

## Usage

### Enable the extension

Edit `LocalSettings.php` and add the following, configuring the settings to suit your needs.

```php
wfLoadExtension('RealUsernames'); // Load me!

// Enable link texts to be replaced by real user names.
$wgRealUsernames_linktext = true;

// Enable link refs to be replaced by real user names.
$wgRealUsernames_linkref = true;

// Show the username together with the real user name.
// (useful for some pages like the "block" one)
$wgRealUsernames_append_username = true;
```

### Other settings

For a better production experience when using real names, it's recommended to, also, configure the following settings. Play with them!

```php
// Disable some preferences.
$wgHiddenPrefs[] = 'nickname';
$wgHiddenPrefs[] = 'realname';

// Use real name in notifications.
$wgEnotifUseRealName = true; 
```

### Suggested hack

As of current version, if you want to get user signatures (and their links to user page and user talk page) also using real names, this hack is needed in core.

```php
diff --git a/includes/parser/Parser.php b/includes/parser/Parser.php
index 5b75287603..b59d5b400f 100644
--- a/includes/parser/Parser.php
+++ b/includes/parser/Parser.php
@@ -4592,7 +4592,7 @@ class Parser {
         * @return string
         */
        public function getUserSig( User $user, $nickname = false, $fancySig = null ) {
-               $username = $user->getName();
+               $username = $user->getRealName(); // UserRealnames hack!
 
                # If not given, retrieve from the user object.
                if ( $nickname === false ) {
```

Note the modification above has been used since Mediawiki 1.17 without problems for sites having the real user names enabled and it has worked perfectly, so far.

Of course, for anybody eager to render the hack not needed anymore and to provide a solution implementing it within the extension, the next chapter may be of interest. ;-)

## Contributing

[Pull requests](https://github.com/stronk7/RealUsernames/pulls) are welcome. For major changes, please [open an issue](https://github.com/stronk7/RealUsernames/issues) first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License and copyright

[BSD 3-Clause](https://choosealicense.com/licenses/bsd-3-clause/) - Copyright (c) 2013 onwards, Eloy Lafuente (stronk7).