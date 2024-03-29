<?php

require_once(dirname(__FILE__).'/../scrape/scrape.php');

define('TMDB_KEY', '263e2042d04c1989170721f79e675028');
define('TMDB_FIND', 'http://api.themoviedb.org/2.1/Movie.search/en/xml/'.TMDB_KEY.'/');
define('TMDB_INFO', 'http://api.themoviedb.org/2.1/Movie.getInfo/en/xml/'.TMDB_KEY.'/');

class TMDB extends Module implements Scraper
{
	public $Name = 'TMDB';
	public $Link = 'http://www.themoviedb.org/';
	public $Icon = 'modules/det_tmdb/icon.png';

	function __construct()
	{
		global $_d;

		$this->CheckActive($this->Name);
		$_d['search.cb.query'][] = array(&$this, 'cb_search_query');
	}

	# Module Extension

	function Link()
	{
		global $_d;

		$_d['movie.cb.query']['columns']["details.{$this->Name}.id"] = 1;
		$_d['movie.cb.query']['columns']["details.{$this->Name}.name"] = 1;
		$_d['movie.cb.query']['columns']["details.{$this->Name}.url"] = 1;
		$_d['movie.cb.query']['columns']["details.{$this->Name}.imdb_id"] = 1;
		$_d['movie.cb.query']['columns']["details.{$this->Name}.released"] = 1;
		$_d['movie.cb.query']['columns']["details.{$this->Name}.certification"] = 1;

		$_d['movie.cb.check'][$this->Name] = array($this, 'movie_cb_check');
		$_d['movie.cb.move'][$this->Name] = array($this, 'movie_cb_move');

		$_d['filter.cb.filters'][$this->Name] = array(&$this, 'filter_cb_filters');
	}

	function Prepare()
	{
		if (!$this->Active) return;

		global $_d;

		if (@$_d['q'][1] == 'covers')
		{
			$id = Server::GetVar('id');

			# Collect Information
			$xml = self::Details($id);
			$sx = simplexml_load_string($xml);
			$covers = $sx->xpath('//movies/movie/images/image[@size="cover"]');

			# Process Information
			$ret = array('id' => $this->Name);
			foreach ($covers as $c)
				$ret['covers'][] = (string)$c['url'];

			die(json_encode($ret));
		}

		if (@$_d['q'][1] == 'fix')
		{
			$type = $_d['q'][2];
			$me = MovieEntry::FromID($_d['q'][3], 'MovieEntry');
			if ($type == 'tmdb_bad_filename')
			{
				$to = $me->Data['errors'][$type]['to'];
				# This is no longer available.
				if (!file_exists($me->Data['path'])) $me->Remove();
				else if ($me->Rename($to))
				{
					$q['_id'] = $me->Data['_id'];
					$u['$set']['path'] = $to;
					$u['$unset']['errors'][$type] = 1;
					$_d['entry.ds']->update($q, $u);
					die(json_encode(array('result' => 'success')));
				}
			}
			die();
		}
	}

	function Get()
	{
		global $_d;

		$js = Module::P('det_tmdb/tmdb.js');
		$r['head'] = '<script type="text/javascript" src="'.$js.'"></script>';

		if (@$_d['q'][0] == 'check')
		{
			$jsc = Module::P('det_tmdb/tmdb_check.js');
			$r['head'] .= '<script type="text/javascript" src="'.$jsc.'"></script>';
		}
		if (@$_d['q'][1] == 'find2')
		{
			$title = MediaLibrary::SearchTitle(Server::GetVar('title'));
			$path = Server::GetVar('path');
			if (Server::GetVar('manual', 0) == 0
				&& preg_match('/.*\((\d+)\)\.\S{3}/', $path, $m))
				$title .= ' '.$m[1];

			die($this->Find($path, $title));
		}
		else if (@$_d['q'][1] == 'remove')
		{
			$_d['entry.ds']->remove(array('_id' => new MongoID(Server::GetVar('id'))));
			die();
		}
		/*else if (@$_d['q'][1] == 'cover')
		{
			$id = $_d['q'][2];
			$item = $_d['entry.ds']->findOne(array('_id' => new MongoID($id)));
			$dst = $_d['config']['paths']['movie-meta'].'/thm_'
				.File::GetFile(basename($item['fs_path']));
			file_put_contents($dst, file_get_contents(Server::GetVar('image')));
			die(json_encode($item));
		}*/
		else if (@$_d['q'][1] == 'fixCover')
		{
			if ($this->FixCover($_POST['path'])) die('Fixed!');
		}

		return $r;
	}

	function movie_cb_move($src_dir, $dst_dir)
	{
		$src_cache = $src_dir.'/.tmdb_cache.json';
		$dst_cache = $dst_dir.'/.tmdb_cache.json';
		if (file_exists($src_cache)) rename($src_cache, $dst_cache);
	}

	function movie_cb_check(&$md)
	{
		# Check for metadata.

		$clean = true;

		if (empty($md->Data['title'])) return false;

		# Do we have a cache?
		if (empty($md->Data['details'][$this->Name]))
		{
			$cache_file = dirname($md->Path).'/.tmdb_cache.json';
			if (file_exists($cache_file))
			{
				$md->Data['details'][$this->Name] =
					json_decode(file_get_contents($cache_file), true);
				$this->Cleanup($md->Data['details'][$this->Name]);
			}
		}

		if (empty($md->Data['details'][$this->Name]))
		{
			$st = MediaLibrary::SearchTitle($md->Data['title']);
			$results = $this->Find($md->Data, $st);
			if (!empty($results))
			foreach ($results as $ix => $r)
			{
				# Year may be off by one.
				if (!empty($md->Data['released']) && ($r['date'] < $md->Data['released']-1
					|| $r['date'] > $md->Data['released']+1)) unset($results[$ix]);
				else if (strtolower(MediaLibrary::SearchTitle($r['title'])) ==
					strtolower($st))
				{
					$results = array($ix => $r);
					break;
				}
			}

			if (count($results) == 1)
			{
				$keys = array_keys($results);
				$this->Scrape($md, $keys[0]);
			}
			else
			{
				$url = "movie/detail/{$md->Data['_id']}?path={$md->Path}";
				echo "<p>Entry <a class=\"a-movie-item\" href=\"$url\">{$md->Path}</a> has no {$this->Name} metadata.</p>\r\n";
				flush();
				return false;
			}
		}

		# @TODO: Check for certification.

		/*if (empty($md->Data['details'][$this->Name]['certification']))
		{
			$uep = urlencode($md->Data['path']);
			$url = "{{app_abs}}/scrape/scrape?path={$uep}";
			$surl = $md->Data['details'][$this->Name]['url'];
			$imdbid = $md->Data['details'][$this->Name]['imdb_id'];

			echo <<<EOD
<a href="{$url}" class="a-fix">Scrape</a> No certification for {$md->Title}
- <a href="{$surl}" target="_blank">{$this->Name}</a>
- <a href="http://www.imdb.com/title/{$imdbid}" target="_blank">IMDB</a>
EOD;
			$clean = false;
		}*/

		# Check filename compliance.

		$filetitle = Movie::CleanTitleForFile($md->Data['details'][$this->Name]['name']);

		if (!empty($md->Data['details'][$this->Name]['released']))
			$date = substr($md->Data['details'][$this->Name]['released'], 0, 4);
		else $date = '';

		$file = $md->Data['path'];
		$ext = File::ext(basename($file));

		$pqr = preg_quote($md->Data['root']);

		# Part files need their CD#
		if (!empty($md->Data['part']))
		{
			$preg = '#^'.$pqr.'/'.preg_quote($filetitle, '#').'/'.preg_quote($filetitle, '#').' \('.$date.'\) CD'
				.$file['part'].'\.(\S+)$#';
			$target = "$filetitle ($date)/$filetitle ($date) CD{$md->Data['part']}.$ext";
		}
		else
		{
			$preg = '#^'.$pqr.'/'.preg_quote($filetitle, '#').' \('.$date.'\)/'.preg_quote($filetitle, '#').' \('.$date.'\)\.(\S+)$#';
			$target = "$filetitle ($date)/$filetitle ($date).$ext";
		}

		global $_d;

		if (!preg_match($preg, $file))
		{
			$urlfix = $_d['app_abs']."/movie/rename?path=".urlencode($file);
			$urlfix .= '&amp;target='.urlencode($md->Data['root'].'/'.$target);
			$urlunfix = $this->Name."/remove?id={$md->Data['_id']}";

			$fulltarget = $md->Data['root'].'/'.$target;

			$this->OutErr("File '$file' should be '$fulltarget'", $md, array(
				'Fix' => $urlfix,
				'Unfix' => $urlunfix
			));

			$clean = false;
		}

		# Check for cover.

		if (!$this->CheckCover($md)) $clean = false;

		return $clean;
	}

	function CheckCover(&$me)
	{
		if (empty($me->Image))
		{
			if (dirname($me->Path) == $me->Data['root'])
				echo "<p>Can't write TMDB cover for {$me->Path}</p>";

			if (empty($me->Data['details'][$this->Name]['images']['image']))
			{
				$this->OutErr("Could not locate a cover.", $me);
				return false;
			}

			$images = $me->Data['details'][$this->Name]['images']['image'];

			if (!empty($images))
			foreach ($images as $img)
			{
				$atrs = $img['@attributes'];
				$types[$atrs['type']][$atrs['size']][] = $atrs;
			}

			foreach ($types as $type => &$sizes)
				foreach ($sizes as $size => &$imgs)
					usort($imgs, function ($a, $b) {
						return ($a['width']+$a['height']) <
							($b['width']+$b['height']);
					});

			if (!empty($types['poster']['cover']))
			{
				$poster = file_get_contents($types['poster']['cover'][0]['url']);
				if (!empty($poster))
				{
					file_put_contents(dirname($me->Path).'/folder.jpg',
						$poster);

					return true;
				}
			}
			else
			{
				echo "<p>Cannot find a cover for {$me->Path}</p>";
				return false;
			}
		}
		return true;
	}

	function cb_search_query($q)
	{
		$ret['$or'][]["details.{$this->Name}.keywords.keyword.@attributes.name"] =
			new MongoRegex("/$q/i");
		$ret['$or'][]["details.{$this->Name}.name"] = new MongoRegex("/$q/i");
		return $ret;
	}

	function filter_cb_filters()
	{
		global $_d;

		$cols = array("details.{$this->Name}.categories.category.@attributes.name" => 1);

		$cats = array();
		foreach ($_d['entry.ds']->find(array(), $cols) as $i)
		{
			if (!empty($i['details'][$this->Name]['categories']['category']))
			foreach ($i['details'][$this->Name]['categories']['category'] as $c)
			if (isset($c['@attributes']))
			{
				$n = $c['@attributes']['name'];
				isset($cats[$n]) ? $cats[$n]++ : $cats[$n] = 0;
			}
		}

		$curcat = Server::GetVar('category');

		if (!empty($cats))
		$sizes = Math::RespectiveSize($cats);

		$items = array();
		foreach ($cats as $n => $c)
		{
			$d['cat_name'] = $n;
			$d['cat_size'] = $sizes[$n];
			$items[] = $d;
		}

		usort($items, function (&$a, &$b)
			{ return $a['cat_size'] < $b['cat_size']; });

		foreach ($items as $i)
		{
			# @TODO: This is a mess, clean it up.
			$ret["{$this->Name}/Categories/{$i['cat_name']}"] = array('href' =>
				'details.'.$this->Name.'.categories.category.@attributes.name": {"$all": ["'
				.$i['cat_name'].'"]}}',
				'style' => "font-size: {$sizes[$i['cat_name']]}px"
			);
		}

		$ret[$this->Name.'/Missing'] = '{"details.'.$this->Name.'": null}';
		return $ret;
	}

	# Scraper Implementation

	function GetName() { return 'The Movie DB'; }
	function CanAuto() { return true; }

	function FindXML($title)
	{
		$title = urlencode(trim($title));
		$xml = @file_get_contents(TMDB_FIND.$title);
		if (empty($xml)) return null;

		$sx = simplexml_load_string($xml);
		$sx_movies = $sx->xpath('//movies/movie');

		return $sx_movies;
	}

	function Find(&$md, $title)
	{
		global $_d;

		if (empty($title)) $title = $md->Title;

		if (empty($title) && !empty($item['details'][$this->Name]['name']))
			$title = $item['details'][$this->Name]['name'];
		else if (empty($title) && !empty($item['title']))
			$title = $item['title'];
		else if (empty($title))
			$fs = MediaEntry::ScrapeFS($path, MovieEntry::GetFSPregs());

		$url = TMDB_FIND.rawurlencode($title);
		try { $xml = file_get_contents($url);}
		catch (Exception $ex)
		{
			die(json_encode(array('msg' => 'Exceptions work!')));
		}

		if (empty($xml)) return;

		$sx = simplexml_load_string($xml);
		$sx_movies = $sx->xpath('//movies/movie');

		$ret = array();
		if (!empty($sx_movies))
		foreach ($sx_movies as $sx_movie)
		{
			$covers = array();
			foreach ($sx_movie->xpath('images/image[@size="cover"]') as $c)
				$covers[] = (string)$c['url'];

			$id = (string)$sx_movie->id;
			$ret[$id] = array(
				'id' => $id,
				'title' => $sx_movie->name,
				'date' => $sx_movie->released,
				'covers' => implode('|', $covers),
				'ref' => (string)$sx_movie->url
			);
		}

		return $ret;
	}

	function GetCovers($item)
	{
		$ret = array();

		foreach ($item->Data['details'][$this->Name]['images']['image'] as $i)
			if ($i['@attributes']['type'] == 'poster')
				$ret[] = $i['@attributes']['url'];

		return $ret;
	}

	function Details($id)
	{
		$ctx = stream_context_create(array('http' => array('timeout' => 3)));
		return file_get_contents(TMDB_INFO.$id, false, $ctx);
	}

	function Scrape(&$me, $id = null)
	{
		if ($id == null)
		{
			$keys = array_keys($this->Find($me->Data['path']));
			if (empty($keys)) return $me;
			$id = $keys[0];
		}
		# Collect remote data
		$data = Arr::FromXML(self::Details($id));
		$data = $data['movies']['movie'];

		# Cache remote info.
		$cache_file = dirname($me->Path).'/.tmdb_cache.json';
		if (dirname($me->Path) != $me->Data['root'])
		{
			file_put_contents($cache_file, json_encode($data));
		}

		$this->Cleanup($data);

		$me->Data['details'][$this->Name] = $data;

		# Try to set the release date on the movie.
		if (!empty($data['released']))
		if (preg_match('/(\d{4})/', $data['released'], $m))
			$me->Data['released'] = $m[1];

		$me->SaveDS();

		// @TODO: These values are bad, we need to fix this system.
		//$ra = $obj['value']['rateAvg'];
		//$va = $obj['value']['voteAvg'];
		//$v = $item['details'][$this->Name]['votes'];
		//$r = $item['details'][$this->Name]['rating'];

		# Badass algorithm here.
		//$item['details'][$this->Name]['score'] =
		//	(($va * $ra) + ($v * $r) / ($va + $v));

		return $me;
	}

	private function Cleanup(&$data)
	{
		# @TODO: Some day do something with the cast maybe.
		unset($data['movies']['movie']['cast']);
	}

	function GetDetails($t, $g, $a)
	{
		if (empty($t->vars['Data']['details'][$this->Name])) return;

		$td = &$t->vars['Data']['details'][$this->Name];

		$ret = array();

		if (!empty($td['url']))
		{
			$i['var'] = 'TMDB_URL';
			$i['val'] = '<a href="'.$td['url'].'" target="_blank">Visit</a>';
			$ret[] = $i;
		}

		if (!empty($td['trailer']))
		{
			preg_match('/\?v=([^&]+)/', $td['trailer'], $m);
			$v = $m[1];
			$i['var'] = 'TMDB_Trailer';
			$i['val'] = <<<EOF
<object width="640" height="360">
	<param name="movie" value="http://www.youtube.com/v/$v&hl=en_US&feature=player_embedded&version=3"></param>
	<param name="allowFullScreen" value="true"></param><param name="allowScriptAccess" value="always"></param>
	<embed src="http://www.youtube.com/v/$v&hl=en_US&feature=player_embedded&version=3" type="application/x-shockwave-flash" allowfullscreen="true" allowScriptAccess="always" width="640" height="360"></embed></object>
EOF;
			$ret[] = $i;
		}

		if (!empty($td['overview']))
		{
			$i['var'] = 'TMDB_Overview';
			$i['val'] = $td['overview'];
			$ret[] = $i;
		}

		/*if (!empty($td['votes']))
		{
			$i['var'] = 'TMDB_Votes';
			$i['val'] = $td['votes'].' votes to '.$td['rating']
				.' rating scores '.$td['score'];
			$ret[] = $i;
		}*/

		return VarParser::Concat($g, $ret);
	}

	private function OutErr($msg, $me, $links = array())
	{
		echo $msg;

		if (!empty($me->Data['details'][$this->Name]['url']))
			echo ' - <a class="button" href="'
				.$me->Data['details'][$this->Name]['url']
				.'" target="_blank">TMDB</a>';
		if (!empty($me->Data['details'][$this->Name]['imdb_id']))
			echo ' - <a class="button" href="http://www.imdb.com/title/'
				.$me->Data['details'][$this->Name]['imdb_id']
				.'" target="_blank">IMDB</a>';
		if (!empty($me->Data['_id']))
			echo ' - <a class="button a-movie-item" href="movie/detail/'
				.$me->Data['_id'].'?path='.$me->Path.'">Details</a>';
		foreach ($links as $t => $l)
		{
			echo ' - <a class="button" href="'.$l.'">'.$t.'</a>';
		}
		echo "</p>\r\n";
		flush();
	}
}

Module::Register('TMDB');
Scrape::Reg('movie', 'TMDB');

?>
