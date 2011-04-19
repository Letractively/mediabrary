<?php

require_once('h_main.php');

class MediaLibrary extends Module
{
	function __construct() { $this->_vars = array(); }

	function Get()
	{
		$t = new Template();
		$t->ReWrite('item', array($this, 'TagItem'));
		$t->Set($this->_vars);
		return $t->Parsefile($this->_template);
	}

	function TagItem($t, $g)
	{
		global $_d;

		$vp = new VarParser();
		$vp->Behavior->Bleed = false;
		$ret = null;
		$scraped = false;

		if (!empty($this->_items))
		foreach ($this->_items as $i)
		{
			# URL friendly path
			$i['reu_path'] = rawurlencode($i['fs_path']);

			# Thumbnail
			$thm_path = $this->_thumb_path.
				'/thm_'.File::GetFile($i['fs_filename']);
			if (file_exists($thm_path))
				$i['med_thumb'] = $this->_thumb_path.
					'/thm_'.File::GetFile($i['fs_filename']);
			else
				$i['med_thumb'] = $this->_missing_image;

			# HTML friendly strings
			foreach (array_keys($i) as $k)
				if (is_string($i[$k]))
					$i[$k] = htmlspecialchars($i[$k]);

			$i['med_thumb'] = str_replace("'", "\\'", $i['med_thumb']);

			$ret .= $vp->ParseVars($g, $i + $_d);
		}

		return $ret;
	}

	static function ScrapeFS($path, $pregs)
	{
		// Collect path based metadata.

		$mx = 0;
		foreach ($pregs as $preg => $matches)
		{
			if (preg_match($preg, $path, $m))
			{
				foreach ($matches as $idx => $col)
					$ret[$col] = $m[$idx];
				$ret['debug_matched'] = $mx;
				break;
			}
			$mx++;
		}

		$ret['fs_path'] = $path;
		$ret['fs_filename'] = basename($path);

		return $ret;
	}

	static function GetMedia($parent_dir, $item, $default_thumb)
	{
		global $_d;

		// Collect the cover

		$path = $item['fs_path'];
		$pinfo = pathinfo($path);
		if (is_file($path))
			$fname = basename($pinfo['basename'], '.'.$pinfo['extension']);
		else $fname = $pinfo['basename'];

		$cover = $parent_dir."/thm_{$fname}";

		if (file_exists($cover)) $ret['med_thumb'] = str_replace("'", "%27",
			'http://'.$_SERVER['HTTP_HOST'].$_d['app_abs'].'/'.$cover);
		else $ret['med_thumb'] = $default_thumb;

		$images = glob($parent_dir."/bd_{$fname}.*");
		if (!empty($images)) $ret['med_bd'] = HM::URL($images[0]);

		return $ret;
	}

	static function CleanTitleForFile($title, $trans_the = true)
	{
		// Regular expression cleanups.
		$preps = array('/\.$/' => '');
		$ret = preg_replace(array_keys($preps), array_values($preps), $title);

		// Literal cleanups.
		$reps = array('/' => ' ', ': ' => ' - ', ':' => '-', '?' => '_', '*' => '_', '"' => "'");

		$ret = str_replace(array_keys($reps), array_values($reps), $ret);

		if ($trans_the)
		{
			// Transpose 'The {title} - {subtitle}
			if (preg_match('/^(the) ([^-]+) - (.*)/i', $ret, $m))
				$ret = $m[2].', '.$m[1].' - '.$m[3];

			// Transpose 'The {title}'
			else if (preg_match('/^(the) (.*)/i', $ret, $m))
				$ret = $m[2].', '.$m[1];
		}

		return $ret;
	}

	static function SearchTitle($title)
	{
		$reps = array(
			'/(.*), The/' => 'The \1',
			'#\[[^\]]+\]#' => '',
			'#([.]{1} |\.|-|_|brrip)#i' => ' ',
			'#\([^)]*\)#' => '',
			"#cd\d+#i" => '');

		return trim(preg_replace(array_keys($reps), array_values($reps), $title));
	}

	static function CleanString($str)
	{
		return trim($str);
	}

	function Check() { return array(); }
}

?>
