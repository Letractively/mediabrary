# Introduction #

Installation is a snap, a few steps and you'll be well on your way.

## Requirements ##

  * Web Server with PHP.
    * You will need mod\_rewrite enabled in your webserver.
    * You will need to add a directive to the mediabrary directory, if your default configuration states AllowOverride None, you will need to specify an alternative for the mediabrary directory, for example AllowOverride All.
  * MySQL (this will change on request).

## Steps ##

### Get ###

  * Mercurial: hg clone https://mediabrary.googlecode.com/hg/ mediabrary
    * Probably a better way to go because you can receive updates much easier.
  * Download: (coming soon)

### Configure ###

  * Copy config/config.default.yml to config/config.yml
  * Edit config/config.yml and set 'db' to something more appropriate.
    * if you want to get creative, you can try other database types and such (mssql, mysqli, sqlite, etc) expect it to break but then you can report related bugs.
  * Go to http://www.yourserver.com/mediabrary (or the place you installed it)
  * Explore all the options and buttons and report any problems you run in to.