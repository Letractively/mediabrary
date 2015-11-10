# Modules #

## Detail Providers ##

### General ###

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| Freebase | Planned    | Will collect data on movie, show, music and picture entries. |

### Movie ###

<a href='http://wiki.mediabrary.googlecode.com/hg/img/meta-movie.jpg'><img src='http://wiki.mediabrary.googlecode.com/hg/img/meta-movie.jpg' width='300' /></a>

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| [OFDB](http://www.ofdb.de) | Working    | German source of movie metadata. |
| [TMDB](http://www.themoviedb.org) | Working    | TheMovieDB.org primary source of data. |
| [RottenTomatoes](http://www.rottentomatoes.com) | Working    | RottenTomatoes.com source of data. |

### TV ###

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| [TVDB](http://thetvdb.com/) | Working    | Collects data from thetvdb.com. |
| [TVRage](http://www.tvrage.com/) | Working    | Collects data from tvrage.com. |

### Music ###

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| [Discogs](http://www.discogs.com) | Mostly Working | Collects music information from discogs.com |
| Last.fm  | Planned    |
| Musicbrainz | Planned    |

### Subtitles ###

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| Allsubs  | Mostly Working | Collects subtitles for a given movie or show. |

# Display #

## Views ##

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| List View | Working    | <a href='http://wiki.mediabrary.googlecode.com/hg/img/list.jpg'><img src='http://wiki.mediabrary.googlecode.com/hg/img/list.jpg' width='300' /></a> |
| Grid View | Working    | <a href='https://lh3.googleusercontent.com/-1WH4vYtLDk8/TSBekLj6OQI/AAAAAAAAABM/a8TQKpkVFF0/s800/4.png'><img src='https://lh3.googleusercontent.com/-1WH4vYtLDk8/TSBekLj6OQI/AAAAAAAAABM/a8TQKpkVFF0/s800/4.png' width='300' /></a> |
| Detail View | Working    | <a href='https://lh6.googleusercontent.com/-DAlV-V2VBTA/TSBepoHfUQI/AAAAAAAAABY/psOTlkuQY2k/s800/6.png'><img src='https://lh6.googleusercontent.com/-DAlV-V2VBTA/TSBepoHfUQI/AAAAAAAAABY/psOTlkuQY2k/s800/6.png' width='300' /></a> |
| Search   | Working    | See List View above. |
| Filter   | Mostly Working | <a href='https://lh4.googleusercontent.com/-XKu4_lfvlQs/TSBekoctx0I/AAAAAAAAABQ/8sB19IvDUcU/s800/5.png'><img src='https://lh4.googleusercontent.com/-XKu4_lfvlQs/TSBekoctx0I/AAAAAAAAABQ/8sB19IvDUcU/s800/5.png' width='300' /></a> |
| Pagination | Working    | When you scroll to the bottom of the page, an additional page will automatically load. |

# Compatibility #

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| Windows Media Center 7 | Planned    | Once someone asks for it. |
| XBMC     | Planned    | Once someone asks for it. |

# Misc #

| **Name** | **Status** | **Description** |
|:---------|:-----------|:----------------|
| Player   | Working    | Plays with anything that will open an M3U or direct link to the file on the server through the webserver. Been tested with android, iphone, vlc and windows media player. _screenshot here_ |
| **Regioning**| Working    | Additionally the player will look for region files that allows you to specify where portions of a given entry is. For example, a show may have the following regions: "Previously On" (which you don't care to watch), "Lead In" (starting off quickly with the cast), "Intro" (where they play intro music and such), "Episode" (where the actual episode takes place) and finally "Credits" (at the end). These can then be skipped forward and back when playing using m3u format. |
| Check Module | Working    | Runs initial checks then calls on any module that can run a check. Most operations involve populating the cache, checking for orphans, removing deleted or renamed files, locating missing metadata, validating filename consistency, etc. This all works, and very well. |
| [TasteKid](http://www.tastekid.com) | Working    | Will offer suggestions on anything from movies to books based on what you ask for. |