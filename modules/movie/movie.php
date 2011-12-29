<?php

class Movie extends MediaLibrary
{
	public $Name = 'movie';

	function __construct()
	{
		parent::__construct();

		global $_d;

		$this->state_file = dirname(__FILE__).'/state.dat';

		$_d['movie_fs.ds'] = $_d['db']->movie_fs;
		$_d['movie_fs.ds']->ensureIndex(array('path' => 1),
			array('unique' => 1, 'dropDups' => 1, 'safe' => 1));
		$_d['movie.source'] = 'file';
		$_d['movie.cb.query']['columns']['path'] = 1;
		$_d['movie.cb.query']['columns']['paths'] = 1;
		$_d['movie.cb.query']['columns']['title'] = 1;

		$_d['movie.cb.query']['match'] = array();

		$_d['entry-types']['movie'] = array('text' => 'Movie',
			'icon' => '<img src="'.Module::P('movie/img/movie.png').'" />');

		$this->_items = array();
		$this->_class = 'movie';

		$this->CheckActive('movie');
	}

	function Link()
	{
		global $_d;

		$_d['nav.links']['Media/Movies'] = '{{app_abs}}/movie';
	}

	function Prepare()
	{
		global $_d;

		if (!$this->Active) return;

		if (@$_d['q'][1] == 'detail')
		{
			$t = new Template();

			$id = $_d['q'][2];
			$data = $_d['entry.ds']->findOne(array('_id' => new MongoId($id)));
			$item = new MovieEntry($data['path'], MovieEntry::GetFSPregs());
			$item->Data += $data;

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

			# Update stored paths.

			$item = $_d['entry.ds']->findOne(array('path' => $path));
			if (!empty($item))
			{
				foreach ($item['paths'] as $ix => $p) if ($p == $path)
					$item['paths'][$ix] = $targ;
				$item['path'] = $targ;
				$_d['entry.ds']->save($item);
			}

			# Update covers or backdrops.

			$pisrc = pathinfo($path);
			$pidst = pathinfo($targ);
			$md = $_d['config']['paths']['movie']['meta'];

			$cover = "$md/thm_".$pisrc['filename'];
			$backd = "$md/bd_".$pisrc['filename'];

			if (file_exists($cover)) rename($cover, "$md/thm_{$pidst['filename']}");
			if (file_exists($backd)) rename($backd, "$md/bd_{$pidst['filename']}");

			if (!file_exists($pidst['dirname'])) mkdir($pidst['dirname'], 0777, true);
			rename($path, $targ);
			die('Fixed');
		}
	}

	function Get()
	{
		global $_d;

		# Main Page

		$ret = '<link type="text/css" rel="stylesheet" href="modules/movie/css.css" />';

		if (!$this->Active) return;

		$_d['movie.cb.query']['limit'] = 50;
		$query = $_d['movie.cb.query'];
		$this->_items = $this->CollectDS();

		if (!empty($_d['movie.cb.filter']))
			$this->_items = U::RunCallbacks($_d['movie.cb.filter'],
				$this->_items);

		if (!empty($_d['movie.cb.fsquery']['limit']))
		{
			$l = $_d['movie.cb.fsquery']['limit'];
			$this->_items = array_splice($this->_items, $l[0], $l[1]);
		}

		$this->_vars['total'] = count($this->_items);

		if (@$_d['q'][1] == 'items')
		{
			$this->_template = 'modules/movie/t_movie_item-grid.xml';
			die(parent::Get());
		}

		$this->_template = 'modules/movie/t_movie.xml';
		$t = new Template();
		$ret .= $t->ParseFile($this->_template);
		$ret .= $_d['movie.add'];

		return $ret;
	}

	function TagDetailItem($t, $g, $a)
	{
		$vp = new VarParser();
		$ret = '';
		if (!empty($this->details))
		foreach ($this->details as $n => $v)
		{
			$ret .= $vp->ParseVars($g, array('name' => $n, 'value' => $v));
		}
		return $ret;
	}

	function Check(&$msgs)
	{
		global $_d;

		$ret = array();

		# General Cleanup

		#$_d['entry.ds']->remove(array('type' => 'movie', 'path' => null));

		# Improve our local file cache for fast access.
		$this->UpdateFSCache();

		$this->_files = $this->CheckFS();

		# Collect database information
		$this->_ds = array();
		foreach ($_d['entry.ds']->find(array('type' => 'movie'),
			$_d['movie.cb.query']['columns']) as $dr)
		{
			$paths = isset($dr['paths']) ? $dr['paths'] : array();
			$paths[] = $dr['path'];

			foreach ($paths as $p)
			{
				# Remove missing items
				if (empty($p) || !file_exists($p))
				{
					$msgs['Cleanup'][] = "Removed database entry for non-existing '"
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
		}

		# Make sure our entry cache is up to date.
		foreach ($this->_files as $p => $movie)
			$ret = array_merge_recursive($ret,
				$this->CheckDatabaseExistence($p, $movie, $msgs));

		$toterrs = 0;

		# Iterate all known combined items.
		/*foreach ($this->_files as $p => $movie)
		{
			# If a provider reports errors on this entry, we do not want to
			# mark it as clean so it will continue to be checked.
			$errors = 0;

			# Database available, run additional checks.
			if (isset($this->_ds[$p]))
			{
				$md = $this->_files[$p];
				$md->Data = $this->_ds[$p];
				$errors += $this->CheckDatabase($p, $md, $msgs);
				$errors += $this->CheckFilename($p, $md, $msgs);
				foreach ($_d['movie.cb.check'] as $cb)
					$errors += call_user_func_array($cb, array(&$md, &$msgs));
			}

			if ($toterrs += $errors > 100) break;

			# If we can, mark this movie clean to skip further checks.
			if (empty($errors) && isset($md) && @$file['fs_part'] < 2)
			{
				$_d['entry.ds']->update(array('_id' => $md->Data['_id']),
					array('$set' => array('mov_clean' => true))
				);
			}
		}*/

		//$this->CheckOrphanMedia($msgs);

		$msgs['Stats'][] = 'Checked '.count($this->_ds).' known movie files.';

		return $ret;
	}

	/*function CheckNextFile()
	{
		if (!file_exists($this->state_file))
			file_put_contents($this->state_file, serialize(array(
				'path' => 0,
				'indices' => array(0)
			)));
		$state = unserialise(file_get_contents($this->state_file));

		if ($state['path'] >= count($_d['config']['paths']['movie']['paths']))
			$state['path'] = 0;

		# Current path we have been working on
		$path = $_d['config']['paths']['movie']['paths'][$state['path']];

		$dp = opendir($dp);
		$dirs = scanndir($dp);

		$this->CheckFile($dirs[$state['indices'][0]]);
	}

	function CheckFile($path, $indices)
	{
	}*/

	/**
	 * Check if a given item exists in the database.
	 * @param MovieEntry $movie
	 * @return array Array of error messages.
	 */
	function CheckDatabaseExistence($file, $md, &$msgs)
	{
		global $_d;

		$ret = array();

		# This is multipart, we only keep track of the first item.
		if (!empty($md->Part)) return $ret;

		if (!isset($this->_ds[$md->Path]))
			$_d['entry.ds']->save($md->Data, array('safe' => 1));

		return $ret;
	}

	function CheckDatabase($p, $md, &$msgs)
	{
		global $_d;

		# The database does not match the filename.
		if (!empty($md->Title) && $md->Title != @$md->Data['title'])
		{
			# Save the file title to the database.
			$_d['entry.ds']->update(array('_id' => $md->Data['_id']),
				array('$set' => array('title' => $md->Title))
			);
		}

		# The database does not have a release date.
		if (!empty($md->Released) && $md->Released != @$md->Data['released'])
			# Save the file title to the database.
			$_d['entry.ds']->update(array('_id' => $md->Data['_id']),
				array('$set' => array('released' => $md->Released))
			);

		# The database has no parent set for a given movie, has to be Movie.
		if (!empty($md->Data['_id']) && empty($md->Data['parent']))
			# Save the file title to the database.
			$_d['entry.ds']->update(array('_id' => $md->Data['_id']),
				array('$set' => array('parent' => 'Movie'))
			);
	}

	function CheckFilename($file, $md, &$msgs)
	{
		global $_d;

		$ext = File::ext($file);

		# Filename related
		if (array_search($ext, array('avi', 'mkv', 'mp4', 'divx')) === false)
		{
			$ret['File Name Compliance'][] = "File {$file} has an unknown extension. ($ext)";
		}

		# Title Related
		$title = $md->Title;

		$title = Movie::CleanTitleForFile($title);

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

			$msgs['Filename Compliance'][] = <<<EOD
<a href="{$urlfix}" class="a-fix">Fix</a>
File "$bn" should be "$target"
EOD;
			return 1;
		}

		return 0;
	}

	function UpdateFSCache()
	{
		global $_d;

		foreach ($_d['config']['paths']['movie']['paths'] as $p)
		{
			$paths = scandir($p);
			foreach ($paths as $i)
			{
				if ($i == '.' || $i == '..') continue;

				$path = urlencode($p.'/'.$i);

				$add = array();
				$add['path'] = $path;
				$_d['movie_fs.ds']->update($add, $add,
					array('safe' => 1, 'upsert' => 1));
			}
		}
	}

	function CheckFS()
	{
		global $_d;

		$ret = array();
		$pregs = MovieEntry::GetFSPregs();
		$exts = MovieEntry::GetExtensions();

		if (!file_exists($this->state_file)) $state = array('index' => 0);
		else $state = unserialize(file_get_contents($this->state_file));

		$res = $_d['movie_fs.ds']->find()
			->skip($state['index']++)
			->limit(1);

		file_put_contents($this->state_file, serialize($state));

		foreach ($res as $p)
		{
			$path = urldecode($p['path']);
			$me = new MovieEntry($path, $pregs);
			$ret[$me->Path] = $me;
		}

		return $ret;
	}

	function CollectDS()
	{
		global $_d;

		if (empty($_d['movie.cb.query']['order']))
			$_d['movie.cb.query']['order'] = array('obtained' => -1);

		$query = $_d['movie.cb.query'];

		if (!empty($_d['movie.cb.lqc']))
			$query = U::RunCallbacks($_d['movie.cb.lqc'], $query);

		$query = array();
		$ret = array();

		# Filtration
		$m = is_array($_d['movie.cb.query']['match']) ?
			$_d['movie.cb.query']['match'] : array();

		# Collect our data
		$cur = $_d['entry.ds']->find($m, $_d['movie.cb.query']['columns']);

		# Sort Order
		if (!empty($_d['movie.cb.query']['order']))
			$cur->sort($_d['movie.cb.query']['order']);

		# Amount of results
		$p = Server::GetVar('page');
		if (!empty($_d['movie.cb.query']['limit']))
		{
			$l = $_d['movie.cb.query']['limit'];
			if (!empty($p)) $cur->skip($p*$l);
			$cur->limit($l);
		}

		foreach ($cur as $i)
		{
			if (empty($i['paths'])) continue;
			$add = new MovieEntry($i['paths'][0]);
			$add->Data = $i;
			$ret[$i['paths'][0]] = $add;
		}

		return $ret;
	}
}

class MovieEntry extends MediaEntry
{
	public $Type = 'movie';

	function __construct($path, $pregs = null)
	{
		# This is a movie folder
		if (is_dir($path))
		{
			$exts = MovieEntry::GetExtensions();
			$files = glob($path.'/*.{'.implode(',', $exts).'}', GLOB_BRACE);
			if (count($files) < 1) return;
			else if (count($files) > 1) var_dump("Invalid movie folder: {$path}");
			else $path = $files[0];
		}

		parent::__construct($path, $pregs);

		global $_d;

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
		$this->Data['paths'] = $this->Paths;
		$this->Data['path'] = $this->Path;
		$this->Data['obtained'] = filemtime($this->Path);
		$this->Data['parent'] = 2;
		$this->Data['class'] = 'object.item.videoItem.movie';
		if (isset($this->Released))
			$this->Data['released'] = $this->Released;
		$this->parent = 'Movie';

		# Collect cover data
		$this->NoExt = File::GetFile($this->Filename);

		$thm_path = dirname($this->Path).'/folder.jpg';

		if (file_exists($thm_path))
			$this->Image = $_d['app_abs'].'/cover?path='.rawurlencode($thm_path);
		else
			$this->Image = 'http://'.$_SERVER['HTTP_HOST'].$_d['app_abs'].'/modules/movie/img/missing.jpg';
	}

	static function GetFSPregs()
	{
		return array(
			# title[date].ext
			'#/([^/]+)\[(\d{4})\][^/]*\.([^.]{3})$#' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Ext'),

			# title (date) CDnum.ext
			'#/([^/]+)\s*\((\d{4})\).*cd(\d+)\.([^.]+)$#i' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Part', 4 => 'Ext'),

			# title (date).ext
			'/\/([^\/]+)\s+\((\d{4})\).*\.([^.]+)$/' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Ext'),

			# title DDDD.ext
			'#([^/]+)(\d{4}).*\.([^.]{3})$#i' => array(
				1 => 'Title', 2 => 'Released', 3 => 'Ext'),

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
			'#([^/]+)\.([^.]{3})#' => array(1 => 'Title', 2 => 'Ext')
		);
	}

	static function GetExtensions()
	{
		return array('avi', 'mkv', 'divx', 'mp4');
	}
}

Module::Register('Movie');

?>
