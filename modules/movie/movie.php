<?php

class Movie extends MediaLibrary
{
	function __construct()
	{
		parent::__construct();

		global $_d;

		$_d['movie.source'] = 'file';
		$_d['movie.cb.query'] = array();
		$_d['movie.cb.query']['columns']['path'] = 1;
		$_d['movie.cb.query']['columns']['paths'] = 1;

		$this->_items = array();
		$this->_class = 'movie';
		$this->_thumb_path = $_d['config']['paths']['movie-meta'];
		$this->_missing_image = 'http://'.$_SERVER['HTTP_HOST'].$_d['app_abs'].
			'/modules/movie/img/missing.jpg';

		$this->CheckActive('movie');
	}

	function Prepare()
	{
		global $_d;

		if (!$this->Active) return;

		if (@$_d['q'][1] == 'detail')
		{
			$t = new Template();

			$item = new MovieEntry(Server::GetVar('path'), self::GetFSPregs());

			if (!empty($_d['q'][2]))
				$item->Data = $_d['entry.ds']->findOne(array('_id' =>
					new MongoID($_d['q'][2])));

			$this->details = array();
			foreach ($_d['movie.cb.detail'] as $cb)
				$this->details = call_user_func_array($cb, array($this->details, $item));

			$t->Set($item);
			$this->_item = $item;
			$t->ReWrite('item', array($this, 'TagDetailItem'));
			die($t->ParseFile('modules/movie/t_movie_detail.xml'));
		}

		else if (@$_d['q'][1] == 'fix')
		{
			# Collect generic information.
			$src = Server::GetVar('path');
			preg_match('#^(.*?)([^/]*)\.(.*)$#', $src, $m);

			# Collect information on this path.
			$item = $_d['entry.ds']->findOne(array('paths' => $src));

			foreach ($item['paths'] as &$p)
			{
				# Figure out what the file should be named.
				$ftitle = $this->CleanTitleForFile($item['title']);
				$fyear = substr($item['released'], 0, 4);

				$dstf = "{$m[1]}{$ftitle} ({$fyear})";
				if (!empty($item['fs_part']))
					$dstf .= " CD{$item['fs_part']}";
				$dstf .= '.'.strtolower($m[3]);

				# Apply File Transformations
				$p = $dstf;
				if (!rename($src, $dstf))
					die("Unable to rename file.");
				@touch($dstf);
			}

			$item['fs_path'] = $item['paths'][0];
			$item['fs_filename'] = basename($item['fs_path']);

			# Rename covers and backdrops as well.
			
			$md = $_d['config']['paths']['movie-meta'];
			$cover = "$md/thm_".File::GetFile($m[2]);
			$backd = "$md/bd_".File::GetFile($m[2]);

			if (file_exists($cover)) rename($cover, "$md/thm_{$ftitle} ({$fyear})");
			if (file_exists($backd)) rename($backd, "$md/bd_{$ftitle} ({$fyear})");

			# Apply Database Transformations

			$_d['entry.ds']->update(array('_id' => $item['_id']),
				$item);

			die('Fixed');
		}
		
		else if (@$_d['q'][1] == 'rename')
		{
			$path = Server::GetVar('path');
			$targ = Server::GetVar('target');
			rename($path, $targ);
			die('Fixed');
		}
	}

	function Get()
	{
		global $_d;

		# Main Page

		$r['head'] = '<link type="text/css" rel="stylesheet"
			href="modules/movie/css.css" />';

		if (empty($_d['q'][0]))
		{
			$text = "Movies";

			$r['default'] = '<div class="main-link" id="divMainMovies"><a
				href="{{app_abs}}/movie" id="a-movie">'.$text.'</a></div>';
			return $r;
		}

		if (!$this->Active) return;

		$query = $_d['movie.cb.query'];
		$this->_files = $this->CollectFS();
		$this->_items = $this->CollectDS();

		if (!empty($_d['movie.cb.filter']))
			$this->_items = U::RunCallbacks($_d['movie.cb.filter'],
				$this->_items, $this->_files);

		if (!empty($_d['movie.cb.fsquery']['limit']))
		{
			$l = $_d['movie.cb.fsquery']['limit'];
			$this->_items = array_splice($this->_items, $l[0], $l[1]);
		}

		$this->_vars['total'] = count($this->_items);

		if (@$_d['q'][1] == 'items')
		{
			$this->_template = 'modules/movie/t_movie_item.xml';
			die(parent::Get());
		}

		$this->_template = 'modules/movie/t_movie.xml';
		$t = new Template();
		$r['default'] = $t->ParseFile($this->_template);
		return $r;
	}

	function TagDetailItem($t, $g, $a)
	{
		$vp = new VarParser();
		if (!empty($this->details))
		foreach ($this->details as $n => $v)
		{
			@$ret .= $vp->ParseVars($g, array('name' => $n, 'value' => $v));
		}
		return @$ret;
	}

	function Check(&$msgs)
	{
		global $_d;

		$ret = array();

		# This will be used later to hunt for things that a file doesn't exist
		# for.
		$filelist = array();

		# Collect known filesystem data
		if (!empty($_d['config']['paths']['movie']))
		foreach ($_d['config']['paths']['movie'] as $p)
		foreach(new FilesystemIterator($p, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS) as $fsi)
		{
			$f = $fsi->GetPathname();
			$this->_files[$f] = new MovieEntry($f, Movie::GetFSPregs());
			$ext = File::ext($f);
			$filelist[] = basename($f, '.'.$ext);
		}

		# Collect database information
		$this->_ds = array();
		foreach ($_d['entry.ds']->find(array(), $_d['movie.cb.query']['columns']) as $dr)
		foreach ($dr['paths'] as $p)
		{
			# Remove missing items
			if (empty($p) || !isset($this->_files[$p]))
			{
				$ret['cleanup'][] = "Removed database entry for non-existing '"
					.$p."'";
				$_d['entry.ds']->remove(array('_id' => $dr['_id']));
			}

			# This one is already clean, skip it.
			if (!empty($dr['clean']))
			{
				unset($this->_files[$p]);
				continue;
			}

			$this->_ds[$p] = $dr;
		}

		# Make sure our entry cache is up to date.
		foreach ($this->_files as $p => $movie)
			$ret = array_merge_recursive($ret,
				$this->CheckDatabaseExistence($movie));

		# Iterate all known combined items.
		foreach ($this->_files as $p => $movie)
		{
			# We reported information in here to place in $ret later.
			$errors = 0;

			# Database available, run additional checks.
			if (isset($this->_ds[$p]))
			{
				$md = $this->_files[$p];
				$md->Data = $this->_ds[$p];
				$errors += $this->CheckFilename($p, $md, $msgs);
				$errors += $this->CheckMedia($p, $md, $msgs);
				foreach ($_d['movie.cb.check'] as $cb)
					$errors += call_user_func_array($cb, array(&$md, &$msgs));
			}

			# If we can, mark this movie clean to skip further checks.
			if (empty($errors) && isset($md) && @$file['fs_part'] < 2)
			{
				$_d['entry.ds']->update(array('_id' => $md->Data['_id']),
					array('$set' => array('mov_clean' => true))
				);
			}
		}

		$ret = array_merge_recursive($ret, $this->CheckOrphanMedia($filelist));

		$ret['Stats'][] = 'Checked '.count($this->_items).' known movie files.';

		return $ret;
	}

	/**
	 * Check if a given item exists in the database.
	 * @param MovieEntry $movie
	 * @return array Array of error messages.
	 */
	function CheckDatabaseExistence($movie)
	{
		global $_d;

		$ret = array();

		# This is multipart, we only keep track of the first item.
		if (!empty($movie->Part)) return $ret;

		if (!isset($this->_ds[$movie->Path]))
			$_d['entry.ds']->save($movie->Data);

		return $ret;
	}

	function CheckFilename($file, $md, &$msgs)
	{
		$ext = File::ext($file);

		# Filename related
		if (array_search($ext, array('avi', 'mkv', 'mp4', 'divx')) === false)
		{
			$ret['File Name Compliance'][] = "File {$file} has an unknown extension. ($ext)";
		}

		# Title Related
		$title = Movie::CleanTitleForFile($md->Title);
		
		# Validate strict naming conventions.

		$next = basename($file, $ext);
		if (isset($md->Released))
		{
			$date = $md->Released;
			$year = substr($date, 0, 4);
		}
		else $year = 'Unknown';

		# Part files need their CD#
		if (!empty($md->Part))
		{
			$preg = '#/'.preg_quote($title, '#').' \('.$year.'\) CD'
				.$file['fs_part'].'\.(\S+)$#';
			$target = "$title ($year) CD{$md['part']}.$ext";
		}
		else
		{
			$preg = '#/'.preg_quote($title, '#').' \('.$year.'\)\.(\S+)$#';
			$target = "$title ($year).$ext";
		}

		if (!preg_match($preg, $file))
		{
			$urlfix = "movie/fix?path=".urlencode($file);
			$bn = basename($file);

			$msgs['Movie/Filename Compliance'][] = <<<EOD
<a href="{$urlfix}" class="a-fix">Fix</a>
File "$bn" should be "$target"
EOD;
			return 1;
		}

		return 0;
	}

	function CheckMedia($file, $md)
	{
		global $_d;

		$ext = File::ext($file);
		$next = basename($file, '.'.$ext);
		$mp = $_d['config']['paths']['movie-meta'];
		if (!empty($md->Part)) return 0;

		#TODO: We don't check TMDB media here.
		# Look for cover or backdrop.
		/*if (!file_exists("$mp/thm_$next"))
		{
			$urlunfix = "tmdb/remove?id={$md['_id']}";
			$ret['Media'][] = <<<EOD
<a href="$urlunfix" class="a-nogo">Unscrape</a> Missing cover for {$md['fs_path']}
- <a href="http://www.themoviedb.org/movie/{$md['tmdbid']}"
target="_blank">Reference</a>
EOD;
		}

		if (!file_exists("$mp/bd_$next"))
		{
				$urlunfix = "tmdb/remove?id={$md['_id']}";
				$ret['Media'][] = <<<EOD
<a href="$urlunfix" class="a-nogo">Unscrape</a> Missing backdrop for {$md['fs_path']}
EOD;
		}*/

		return 0;
	}

	/**
	 * Check for orphaned meta images.
	 */
	function CheckOrphanMedia($filelist)
	{
		global $_d;

		$ret = array();

		$mp = $_d['config']['paths']['movie-meta'];
		foreach (glob("$mp/movie/*") as $p)
		{
			$f = basename($p);

			# Proper named thumbnail or backdrop.
			if (preg_match('/(thm_|bd_)(.*)/', $f, $m))
			{
				if (array_search($m[2], $filelist) !== false) continue;

				if (!unlink($p))
					$ret['Media'][] = "Could not unlink: $p";
				else $ret['Media'][] = "Removed orphan cover $p";
			}
			else
			{
				if (!unlink($p))
					$ret['Media'][] = "Could not unlink: $p";
				else $ret['Media'][] = "Removed irrelevant cover: {$p}";
			}
		}
		return $ret;
	}

	function CollectFS()
	{
		global $_d;

		$ret = array();

		$pregs = Movie::GetFSPregs();

		if (!empty($_d['config']['paths']['movie']))
		foreach ($_d['config']['paths']['movie'] as $p)
		foreach (new FilesystemIterator($p, FileSystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS) as $f)
		{
			$path = $f->GetPathname();
			$ret[$path] = new MovieEntry($path, $pregs);
		}

		return $ret;
	}

	function CollectDS()
	{
		global $_d;

		if (empty($_d['movie.cb.query']['limit']) && empty($_d['movie.cb.nolimit']))
			$_d['movie.cb.query']['limit'] = 50;
		if (empty($_d['movie.cb.query']['match']))
			$_d['movie.cb.query']['order'] = array('obtained' => -1);

		$query = $_d['movie.cb.query'];

		if (!empty($_d['movie.cb.lqc']))
			$query = U::RunCallbacks($_d['movie.cb.lqc'], $query);

		$query = array();
		$ret = array();

		$m = !empty($_d['movie.cb.query']['match']) ? $_d['movie.cb.query']['match'] : array();
		$cur = $_d['entry.ds']->find($m, $_d['movie.cb.query']['columns']);
		$p = Server::GetVar('page');
		if (!empty($_d['movie.cb.query']['limit']))
		{
			$l = $_d['movie.cb.query']['limit'];
			if (!empty($p)) $cur->skip($p*$l);
			$cur->limit($l);
		}
		if (!empty($_d['movie.cb.query']['order']))
			$cur->sort($_d['movie.cb.query']['order']);
		foreach ($cur as $i)
		{
			$add = new MovieEntry($i['paths'][0]);
			$add->Data = $i;
			$ret[$i['paths'][0]] = $add;
		}

		return $ret;
	}

	static function GetFSPregs()
	{
		return array(
			# title [date].ext
			'#/([^/[\]]+)\s*\[([0-9]{4})\].*\.([^.]*)$#' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Ext'),

			# title (date).ext
			'/\/([^\/]+)\s+\((\d{4})\).*\.([^.]+)$/' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Ext'),

			# title (date) CDnum.ext
			'#/([^/]+)\s*\((\d{4})\).*cd(\d+)\.([^.]+)$#i' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Part', 4 => 'Ext'),

			# title CDnum.ext
			'#/([^/]+).*cd(\d+)\.([^.]+)$#i' => array(
				1 => 'Title', 2 => 'Part', 3 => 'Ext'),

			# title [strip].ext
			'#/([^/]+)\s*\[.*\.([^.]+)$#' => array(
				1 => 'Title', 2 => 'Ext'),

			# title.ext
			'#([^/(]+?)[ (.]*(ac3|dvdrip|xvid|limited|dvdscr).*\.([^.]+)$#i' => array(
				1 => 'Title', 3 => 'Ext'),

			# title.ext
			'#([^/]+)\s*\.(\S+)$#' => array(
				1 => 'Title', 2 => 'Ext')
		);
	}
}

class MovieEntry extends MediaEntry
{
	function __construct($path, $pregs = null)
	{
		parent::__construct($path, $pregs);

		# This is a part, lets try to find the rest of them.
		if (!empty($this->Part))
		{
			$qg = File::QuoteGlob($this->Path);
			$search = preg_replace('/CD\d+/i', '[Cc][Dd]*', $qg);
			$files = glob($search);
			$this->Paths = $files;
		}
		# Just a single movie
		else $this->Paths[] = $this->Path;

		# Default data values for every movie entry.
		$this->Data['title'] = $this->Title;
		if (isset($this->Released)) $this->Data['released'] = $this->Released;
		$this->Data['paths'] = $this->Paths;
		$this->Data['path'] = $this->Path;
		$this->Data['obtained'] = filemtime($this->Path);
	}
}

Module::Register('Movie');

?>
