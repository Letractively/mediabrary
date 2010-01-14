<?php

require_once('h_main.php');

class MediaDisplay extends Module
{
	function Get()
	{
		$t = new Template();
		$t->ReWrite('item', array($this, 'TagItem'));
		return $t->Parsefile($this->_template);
	}

	function TagItem($t, $g)
	{
		global $_d;

		$vp = new VarParser();
		$ret = null;
		$scraped = false;

		foreach ($this->_items as $i)
		{
			$m = $this->_metadata[$i];
			$ret .= $vp->ParseVars($g, $m);
		}

		return $ret;
	}

	function ScrapeFS($path)
	{
		// Collect path based metadata.

		if (!isset($this->_metadata[$path]))
		{
			foreach ($this->_fs_scrapes as $preg => $matches)
			{
				if (preg_match($preg, $path, $m))
				{
					foreach ($matches as $idx => $col)
						$this->_metadata[$path][$col] = $m[$idx];
					break;
				}
			}

			$this->_metadata[$path]['med_path'] = $path;
			$this->_metadata[$path]['med_filename'] = basename($path);
		}

		// Collect the cover

		$pinfo = pathinfo($path);
		$pinfo['filename'] = filenoext($pinfo['basename']);
		$images = glob("img/meta/{$this->_class}/thm_{$pinfo['filename']}.*");

		if (!empty($images))
			$this->_metadata[$path]['med_thumb'] = URL($images[0]);
		else $this->_metadata[$path]['med_thumb'] = "img/missing-{$this->_class}.jpg";

		return $this->_metadata[$path];
	}
}

?>
