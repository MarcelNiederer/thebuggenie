<?php

	/**
	 * Text parser class
	 *
	 * @author Daniel Andre Eikeland <zegenie@zegeniestudios.net>
	 * @version 2.0
	 * @license http://www.opensource.org/licenses/mozilla1.1.php Mozilla Public License 1.1 (MPL 1.1)
	 * @package thebuggenie
	 * @subpackage main
	 */

	/**
	 * Text parser class
	 *
	 * @package thebuggenie
	 * @subpackage main
	 */
	class TBGTextParser
	{

		protected static $additional_regexes = null;
		protected static $current_parser = null;

		protected $preformat = null;
		protected $tablemode = null;
		protected $opentablecol = false;
		protected $options = array();
		protected $use_toc = false;
		protected $toc_base_id = null;
		protected $openblocks = array();
		protected $nowikis = array();
		protected $codeblocks = array();
		protected $linknumber = 0;
		protected $internallinks = array();
		protected $categories = array();
		protected $ignore_newline = false;
		protected $parsed_text = null;
		protected $toc = array();
		protected $text = null;

		public static function addRegex($regex, $callback)
		{
			if (self::$additional_regexes === null) self::$additional_regexes = array();
			self::$additional_regexes[] = array($regex, $callback);
		}

		/**
		 * Returns the current parser object, only valid when the _parseText method is running,
		 *
		 * @return TBGTextParser
		 */
		public static function getCurrentParser()
		{
			return self::$current_parser;
		}

		/**
		 * Return an array of the registered regexes to be parsed
		 *
		 * @return array
		 */
		protected static function getRegexes()
		{
			if (self::$additional_regexes === null) self::$additional_regexes = array();
			return self::$additional_regexes;
		}

		/**
		 * Setup the parser object
		 *
		 * @param string $text The text to be parsed
		 * @param boolean $use_toc[optional] Whether to use a TOC if found
		 * @param string $toc_base_id[optional] Base id to use for the TOC element
		 */
		public function __construct($text, $use_toc = false, $toc_base_id = null)
		{
			$this->text = $text;
			$this->use_toc = $use_toc;
			$this->toc_base_id = $toc_base_id;

			if (TBGContext::isProjectContext())
			{
				$this->namespace = TBGContext::getCurrentProject()->getKey();
			}
			if (TBGContext::getEnvironment() != TBGContext::ENV_CLI)
			{
				TBGContext::loadLibrary('ui');
			}
		}

		public function addInternalLinkOccurrence($article_name)
		{
			(!array_key_exists($article_name, $this->internallinks)) ? $this->internallinks[$article_name] = 1 : $this->internallinks[$article_name]++;
		}

		public function addCategorizer($category)
		{
			$this->categories[$category] = true;
		}

		protected function _parse_headers($matches)
		{
			if (array_key_exists('headers', $this->options) && !$this->options['headers'])
			{
				return htmlspecialchars($matches[0]) . "\n";
			}
			
			$level = strlen($matches[1]);
			$content = $matches[2];
			$this->stop = true;

			// avoid accidental run-on openblocks
			$retval = $this->_emphasize_off() . "\n";

			$retval .= "<h{$level}";
			if ($this->use_toc)
			{
				$id = $this->toc_base_id . '_toc_' . (count($this->toc) + 1);
				$this->toc[] = array('level' => $level, 'content' => $content, 'id' => $id);
				$retval .= " id=\"{$id}\"";
			}
			$retval .= ">" . htmlspecialchars($content);
			if (!isset($this->options['embedded']) || $this->options['embedded'] == false)
			{
				$retval .= "&nbsp;<a href=\"#top\">&uArr;&nbsp;".__('top')."</a>";
			}
			$retval .= "</h{$level}>\n";

			return $retval;
		}

		protected function _parse_newline($matches)
		{
			if ($this->ignore_newline) return $this->_emphasize_off();

			$this->stop = true;
			// avoid accidental run-on openblocks
			return $this->_emphasize_off() . "<br><br>";
		}

		protected function _parse_list($matches, $close = false)
		{
			$listtypes = array('*' => 'ul', '#' => 'ol');
			$output = "";

			$newlevel = ($close) ? 0 : strlen($matches[1]);

			while ($this->list_level != $newlevel)
			{
				$listchar = substr($matches[1],-1);
				if (is_string($listchar) || is_numeric($listchar))
				{
					$listtype = $listtypes[$listchar];
				}
				else
				{
					$listtype = 'ul';
				}

				if ($this->list_level > $newlevel)
				{
					$listtype = '/'.array_pop($this->list_level_types);
					$this->list_level--;
				} 
				else
				{
					$this->list_level++;
					array_push($this->list_level_types,$listtype);
				}
				$output .= "\n<{$listtype}>\n";
			}

			if ($close)
			{
				return $output;
			}
			else
			{
				$output .= "<li>".htmlspecialchars($matches[2])."</li>\n";
				return $output;
			}
		}

		protected function _parse_definitionlist($matches, $close = false)
		{
			if ($close)
			{
				$this->deflist = false;
				return "</dl>\n";
			}

			$output = "";
			if (!$this->deflist) $output .= "<dl>\n";
			$this->deflist = true;

			switch ($matches[1])
			{
				case ';':
					$term = htmlspecialchars($matches[2]);
					$p = strpos($term, ' :');
					if ($p !== false)
					{
						list($term, $definition) = explode(':', $term);
						$output .= "<dt>{$term}</dt><dd>{$definition}</dd>";
					} 
					else
					{
						$output .= "<dt>{$term}</dt>";
					}
					break;
				case ':':
					$definition = htmlspecialchars($matches[2]);
					$output .= "<dd>{$definition}</dd>\n";
					break;
			}

			return $output;
		}

		protected function _parse_preformat($matches, $close = false)
		{
			if ($close)
			{
				$this->preformat = false;
				return "</pre>\n";
			}

			$this->stop_all = true;

			$output = "";
			if (!$this->preformat) $output .= "<pre>";
			$this->preformat = true;

			$output .= htmlspecialchars($matches[1]);

			return $output."\n";
		}

		protected function _parse_horizontalrule($matches)
		{
			return "<hr />";
		}

		protected function _parse_underline($matches)
		{
			return "<u>".htmlspecialchars($matches[1])."</u>";
		}

		protected function _wiki_link($topic)
		{
			return ucfirst(str_replace(' ', '_', $topic));
		}

		protected function _parse_image($href,$title,$options)
		{
			if ($this->ignore_images) return "";
			if (!$this->image_uri) return $title;

			$href = $this->image_uri . $href;

			$imagetag = sprintf('<img src="%s" alt="%s" />', $href, $title);
			foreach ($options as $k=>$option)
			{
				switch($option)
				{
					case 'frame':
						$imagetag = sprintf('<div style="float: right; background-color: #F5F5F5; border: 1px solid #D0D0D0; padding: 2px">%s<div>%s</div></div>', $imagetag, $title);
						break;
					case 'right':
						$imagetag = sprintf('<div style="float: right">%s</div>', $imagetag);
						break;
				}
			}

			return $imagetag;
		}

		protected function _parse_internallink($matches)
		{
			$href = $matches[4];
			if (TBGContext::getEnvironment() == TBGContext::ENV_CLI)
			{
				return $href;
			}
			if (isset($matches[6]) && $matches[6])
			{
				$title = $matches[6];
			}
			else
			{
				$title = $href;
				if (isset($matches[7]) && $matches[7])
				{
					$title .= $matches[7];
				}
			}
			$namespace = $matches[3];

			if (strtolower($namespace) == 'image')
			{
				$options = explode('|', $title);
				$title = array_pop($options);

				return image_tag($href, array('alt' => htmlspecialchars($title))); // $this->parse_image($href,$title,$options);
			}

			if (strtolower($namespace) == 'category')
			{
				if (substr($matches[2], 0, 1) != ':')
				{
					$this->addCategorizer(htmlspecialchars($href));
					return '';
				}
			}

			if (strtolower($namespace) == 'wikipedia')
			{
				$options = explode('|', $title);
				$title = array_pop($options);

				return link_tag('http://en.wikipedia.org/wiki/'.$href, $namespace.':'.htmlspecialchars($title)); // $this->parse_image($href,$title,$options);
			}

			if ($namespace == 'TBG')
			{
				$options = explode('|',$title);
				$title = array_pop($options);

				return link_tag(make_url($href), htmlspecialchars($title)); // $this->parse_image($href,$title,$options);
			}

			if (substr($href, 0, 1) == '/')
			{
				$options = explode('|', $title);
				$title = array_pop($options);

				return link_tag($href, htmlspecialchars($title)); // $this->parse_image($href,$title,$options);
			}

			$title = preg_replace('/\(.*?\)/', '', $title);
			$title = preg_replace('/^.*?\:/', '', $title);

			if (!$namespace || !array_key_exists($namespace, array('ftp', 'http', 'https', 'gopher', 'mailto', 'news', 'nntp', 'telnet', 'wais', 'file', 'prospero', 'aim', 'webcal')))
			{
				if ($namespace) $href = $namespace . ':' . $href;
				$href = $this->_wiki_link($href);
				$title = $href;
				$this->addInternalLinkOccurrence($href);
				$href = TBGContext::getRouting()->generate('publish_article', array('article_name' => $href));
			}
			else
			{
				$href = $namespace.':'.$this->_wiki_link($href);
			}

			return link_tag($href, htmlspecialchars($title));
		}

		protected function _parse_externallink($matches)
		{
			$href = $matches[2];
			if (TBGContext::getEnvironment() == TBGContext::ENV_CLI)
			{
				return $href;
			}
			TBGContext::loadLibrary('ui');
			$title = null;
			$title = (array_key_exists(3, $matches)) ? $matches[3] : $matches[2];
			if (!$title)
			{
				$this->linknumber++;
				$title = "[{$this->linknumber}]";
			}

			return link_tag($href, htmlspecialchars($title), array('target' => '_new'));
		}

		protected function _parse_autosensedlink($matches)
		{
			return $this->_parse_externallink(array('', '', $matches[0]));
		}

		protected function _emphasize($level)
		{
			$levels = array(2 => array('<i>','</i>'), 3 => array('<b>','</b>'), 4 => array('<b>','</b>'), 5 => array('<i><b>','</b></i>'));

			$output = "";

			// handle cases where bold/italic words ends with an apostrophe, eg: ''somethin'''
			// should read <em>somethin'</em> instead of <em>somethin<strong>
			if ((!isset($this->openblocks[$level]) || (isset($this->openblocks[$level]) && !$this->openblocks[$level])) && (isset($this->openblocks[$level - 1]) && $this->openblocks[$level - 1]))
			{
				$level--;
				$output = "'";
			}

			$offset = (isset($this->openblocks[$level])) ? (int) $this->openblocks[$level] : 0;
			$output .= $levels[$level][$offset];

			$this->openblocks[$level] = !$offset;

			return $output;
		}

		protected function _parse_emphasize($matches)
		{
			$amount = strlen($matches[1]);
			return $this->_emphasize($amount);
		}

		protected function _emphasize_off()
		{
			$output = "";
			if (count($this->openblocks))
			{
				foreach ($this->openblocks as $amount =>$state) {
					if ($state) $output .= $this->_emphasize($amount);
				}
			}

			return $output;
		}

		protected function _parse_eliminate($matches)
		{
			return "";
		}

		protected function _parse_issuelink($matches)
		{
			$theIssue = TBGIssue::getIssueFromLink($matches[0]);
			$output = '';
			$classname = '';
			if ($theIssue instanceof TBGIssue && ($theIssue->isClosed() || $theIssue->isDeleted()))
			{
				$classname = 'closed';
			}
			if ($theIssue instanceof TBGIssue)
			{
				$output = link_tag(make_url('viewissue', array('issue_no' => $theIssue->getIssueNo(true), 'project_key' => $theIssue->getProject()->getKey())), $theIssue->getFormattedIssueNo() . ' - ' . $theIssue->getTitle(), array('class' => $classname));
			}
			else
			{
				$output = htmlspecialchars($matches[1]);
			}
			return $output;
		}

		protected function _parse_variable($matches)
		{
			switch($matches[2])
			{
				case 'CURRENTMONTH':
					return date('m');
				case 'CURRENTMONTHNAMEGEN':
				case 'CURRENTMONTHNAME':
					return date('F');
				case 'CURRENTDAY':
					return date('d');
				case 'CURRENTDAYNAME':
					return date('l');
				case 'CURRENTYEAR':
					return date('Y');
				case 'CURRENTTIME':
					return date('H:i');
				case 'NUMBEROFARTICLES':
					return 0;
				case 'PAGENAME':
					return TBGContext::getResponse()->getPage();
				case 'NAMESPACE':
					return 'None';
				case 'TOC':
					return '{{TOC}}';
				case 'SITENAME':
					return TBGSettings::getTBGname();
				case 'SITETAGLINE':
					return TBGSettings::getTBGtagline();
				default:
					return '';
			}
		}

		protected function _parse_tableopener($matches)
		{
			$output = "<table";
			if (array_key_exists(1, $matches))
			{
				$output .= $matches[1];
			}
			if (strpos($output, "cellspacing") === false)
			{
				$output .= " cellspacing=0";
			}
			$output .= ">";
			$this->tablemode = true;
			$this->opentablecol = false;
			$this->stop_all = true;

			return $output;
		}

		protected function _parse_tablecloser($matches)
		{
			$this->tablemode = false;
			$this->stop_all = true;
			$output = '';
			if ($this->opentablecol == true)
			{
				$output .= '</td></tr>';
				$this->opentablecol = false;
			}
			$output .= "</table>";

			return $output;
		}

		protected function _parse_tablerow($matches)
		{
			$this->stop_all = true;
			$output = '';
			if ($this->opentablecol == true)
			{
				$output .= '</td></tr>';
				$this->opentablecol = false;
			}
			$output .= "<tr>";

			return $output;
		}

		protected function _parse_tableheader($matches)
		{
			$this->opentablecol = false;
			$output = '<thead>';
			if (array_key_exists(1, $matches))
			{
				$cols = explode(' !! ', $matches[1]);
				foreach ($cols as $col)
				{
					$col = htmlspecialchars($col);
					$output .= "<th>{$col}</th>";
				}
			}
			$output .= "</thead>";

			return $output;
		}

		protected function _parse_tablerowcontent($matches)
		{
			$this->opentablecol = true;
			$first = true;
			$output = '<td>';
			if (array_key_exists(1, $matches))
			{
				$cols = explode(' || ', $matches[1]);
				foreach ($cols as $col)
				{
					if (!$first) $output .= "</td><td>";
					$output .= htmlspecialchars($col);
					$output .= '<br>';
					$first = false;
				}
			}

			return $output;
		}

		protected function _getsmiley($smiley_code)
		{
			switch ($smiley_code[1])
	        {
				case ":(":
				case ":-(":
					return image_tag('smileys/4.png');
				case ":)":
				case ":-)":
					return image_tag('smileys/2.png');
				case "8)":
				case "8-)":
					return image_tag('smileys/3.png');
				case "B)":
				case "B-)":
					return image_tag('smileys/3.png');
				case ":-/":
					return image_tag('smileys/10.png');
				case ":D":
				case ":-D":
					return image_tag('smileys/5.png');
				case ":P":
				case ":-P":
					return image_tag('smileys/6.png');
				case "(!)":
					return image_tag('smileys/8.png');
				case "(?)":
					return image_tag('smileys/9.png');
			}
		}

		protected function _parse_line($line, $options = array())
		{
			$line_regexes = array();
			
			$line_regexes['preformat'] = '^\s{1}(.*?)$';
			$line_regexes['definitionlist'] = '^([\;\:])(?!\-?[\(\)\D\/P])\s*(.*?)$';
			$line_regexes['newline'] = '^$';
			$line_regexes['list'] = '^([\*\#]+)(.*?)$';
			$line_regexes['tableopener'] = '^\{\|(.*?)$';
			$line_regexes['tablecloser'] = '^\|\}$';
			$line_regexes['tablerow'] = '^\|-$';
			$line_regexes['tableheader'] = '^\!\s{1}(.*?)$';
			$line_regexes['tablerowcontent'] = '^\|{1,2}\s{1}(.*?)$';
			$line_regexes['headers'] = '^(={1,6})(.*?)(={1,6})$';
			$line_regexes['horizontalrule'] = '^----$';

			$char_regexes = array();
			$char_regexes[] = array('/(\[\[(\:?([^\]]*?)\:)?([^\]]*?)(\|([^\]]*?))?\]\]([a-z]+)?)/i', array($this, '_parse_internallink'));
			$char_regexes[] = array('/(\[([^\]]*?)(\s+[^\]]*?)?\])/i', array($this, '_parse_externallink'));
			$char_regexes[] = array('/(\'{2,5})/i', array($this, '_parse_emphasize'));
			$char_regexes[] = array('/(__NOTOC__|__NOEDITSECTION__)/i', array($this, '_parse_eliminate'));
			if (!array_key_exists('ignore_vars', $options))
			{
				$char_regexes[] = array('/(\{\{([^\}]*?)\}\})/i', array($this, '_parse_variable'));
			}
			$char_regexes[] = array('#(?<!\!)((bug|issue|ticket|story)\s\#?(([A-Z0-9]+\-)?\d+))#i', array($this, '_parse_issuelink'));
			$char_regexes[] = array('/(\:\(|\:-\(|\:\)|\:-\)|8\)|8-\)|B\)|B-\)|\:-\/|\:-D|\:-P|\(\!\)|\(\?\))/i', array($this, '_getsmiley'));
			$char_regexes[] = array('/(^|[ \t\r\n])((ftp|http|https|gopher|mailto|news|nntp|telnet|wais|file|prospero|aim|webcal):(([A-Za-z0-9$_.+!*(),;\/?:@&~=-])|%[A-Fa-f0-9]{2}){2,}(#([a-zA-Z0-9][a-zA-Z0-9$_.+!*(),;\/?:@&~=%-]*))?([A-Za-z0-9$_+!*();\/?:~-]))/', array($this, '_parse_autosensedlink'));
			foreach (self::getRegexes() as $regex)
			{
				$char_regexes[] = array($regex[0], $regex[1]);
			}

			$this->stop = false;
			$this->stop_all = false;

			$called = array();
			//$line = rtrim($line);

			//$trimmed_line = rtrim($line);

			//if ($trimmed_line) $line = $trimmed_line;

			//if (substr($line, -1) == " ")
			//{
				//$line = substr($line, 0, -1);
			//}

			foreach ($line_regexes as $func => $regex)
			{
				//TBGLogging::log($func, 'parsing', 5);
				if (preg_match("/$regex/i", $line, $matches))
				{
					$called[$func] = true;
					$func = "_parse_".$func;
					$line = $this->$func($matches);
					if ($this->stop || $this->stop_all) break;
				}
			}
			
			if (!$this->stop_all)
			{
				$this->stop = false;
				foreach ($char_regexes as $regex)
				{
					$line = preg_replace_callback($regex[0], $regex[1], $line);
					if ($this->stop) break;
				}
			}

			$isline = (bool) (strlen(trim($line)) > 0);

			// if this wasn't a list item, and we are in a list, close the list tag(s)
			if (($this->list_level > 0) && !array_key_exists('list', $called)) $line = $this->_parse_list(false, true) . $line;
			if ($this->deflist && !array_key_exists('definitionlist', $called)) $line = $this->_parse_definitionlist(false, true) . $line;

			if ($this->preformat && !array_key_exists('preformat', $called)) $line = $this->_parse_preformat(false, true) . $line;

			//if ($this->tablemode && (!array_key_exists('tablerow', $called) && !array_key_exists('tableopener', $called))) $line = $this->_parse_tablecloser(array()) . $line;

			// suppress linebreaks for the next line if we just displayed one; otherwise re-enable them
			if ($isline) $this->ignore_newline = (array_key_exists('newline', $called) || array_key_exists('headers', $called));

			return $line;
		}
		
		protected function _parseText($options = array())
		{
			self::$current_parser = $this;
			$this->list_level_types = array();
			$this->list_level = 0;
			$this->deflist = false;
			$this->ignore_newline = false;
			
			$output = "";
			$text = $this->text;
			
			$text = preg_replace_callback('/<nowiki>(.+?)<\/nowiki>(?!<\/nowiki>)/ism', array($this, "_parse_save_nowiki"), $text);
			$text = preg_replace_callback('/<source((?:\s+[^\s]+=".*?")*)>\s*(.*?)\s*<\/source>/ism', array($this, "_parse_save_code"), $text);
			// Thanks to Mike Smith (scgtrp) for the above regexp

			$lines = explode("\n", $text);
			//var_dump($text);
			foreach ($lines as $line)
			{
				if (substr($line, -1) == "\r")
				{
					$line = substr($line, 0, -1);
				}
				//var_dump($line);
				$output .= $this->_parse_line($line, $options);
			}
			//die();
			
			$this->nowikis = array_reverse($this->nowikis);
			$this->codeblocks = array_reverse($this->codeblocks);

			if (!array_key_exists('ignore_toc', $options))
			{
				$output = preg_replace_callback('/\{\{TOC\}\}/', array($this, "_parse_add_toc"), $output);
			}
			$output = preg_replace_callback('/\|\|\|NOWIKI\|\|\|/i', array($this, "_parse_restore_nowiki"), $output);
			$output = preg_replace_callback('/\|\|\|CODE\|\|\|/i', array($this, "_parse_restore_code"), $output);

			self::$current_parser = null;
			return $output;
		}

		public function getParsedText()
		{
			if ($this->parsed_text === null)
			{
				$this->parsed_text = $this->_parseText();
			}
			return $this->parsed_text;
		}

		public function doParse($options = array())
		{
			if ($this->parsed_text === null)
			{
				$this->parsed_text = $this->_parseText($options);
			}
		}

		protected function _parse_add_toc($matches)
		{
			if (TBGContext::getEnvironment() == TBGContext::ENV_CLI)
			{
				return true;
			}
			return TBGAction::returnTemplateHTML('publish/toc', array('toc' => $this->toc));
		}

		protected function _parse_save_nowiki($matches)
		{
			array_push($this->nowikis, $matches[1]);
			return "|||NOWIKI|||";
		}

		protected function _parse_restore_nowiki($matches)
		{
			return htmlspecialchars(array_pop($this->nowikis));
		}

		protected function _parse_save_code($matches)
		{
			array_push($this->codeblocks, $matches);
			return "|||CODE|||";
		}

		protected function _geshify($matches)
		{
			$codeblock = $matches[2];
			$params = $matches[1];

			$language = preg_match('/(?<=lang=")(.*?)(?=")/', $params, $matches);

			if ($language !== 0)
			{
				$language = $matches[0];
			} 
			else
			{
				$language = TBGSettings::get('highlight_default_lang');
			}
			
			$numbering_startfrom = preg_match('/(?<=line start=")(.*?)(?=")/', $params, $matches);
			if ($numbering_startfrom !== 0)
			{
				$numbering_startfrom = (int) $matches[0];
			} 
			else
			{
				$numbering_startfrom = 1;
			}
			
			$geshi = new GeSHi($codeblock, $language);
			
			$highlighting = preg_match('/(?<=line=")(.*?)(?=")/', $params, $matches);
			if ($highlighting !== 0)
			{
				$highlighting = $matches[0];
			} 
			else
			{
				$highlighting = false;
			}
			
			$interval = preg_match('/(?<=highlight=")(.*?)(?=")/', $params, $matches);
			if ($interval !== 0)
			{
				$interval = $matches[0];
			} 
			else
			{
				$interval = TBGSettings::get('highlight_default_interval');
			}

			if ($highlighting === false)
			{
				switch (TBGSettings::get('highlight_default_numbering'))
				{
					case 1:
						// Line numbering with a highloght every n rows
						$geshi->enable_line_numbers(GESHI_FANCY_LINE_NUMBERS, $interval);
						$geshi->start_line_numbers_at($numbering_startfrom);
						break;
					case 2:
						// Normal line numbering
						$geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS, 10);
						$geshi->start_line_numbers_at($numbering_startfrom);
						break;
					case 3:
						break; // No numbering
				}
			}
			else
			{
				switch($highlighting)
				{
					case 'highlighted':
					case 'GESHI_FANCY_LINE_NUMBERS':
						// Line numbering with a highloght every n rows
						$geshi->enable_line_numbers(GESHI_FANCY_LINE_NUMBERS, $interval);
						$geshi->start_line_numbers_at($numbering_startfrom);
						break;
					case 'normal':
					case 'GESHI_NORMAL_LINE_NUMBERS':
						// Normal line numbering
						$geshi->enable_line_numbers(GESHI_NORMAL_LINE_NUMBERS, 10);
						$geshi->start_line_numbers_at($numbering_startfrom);
						break;
					case 3:
						break; // No numbering
				}
			}

			$codeblock = $geshi->parse_code();
			unset($geshi);
			return '<code>' . $codeblock . '</code>';
		}

		protected function _parse_restore_code($matches)
		{
			return $this->_geshify(array_pop($this->codeblocks));
		}

		public function getInternalLinks()
		{
			return $this->internallinks;
		}

		public function getCategories()
		{
			return $this->categories;
		}

		public function setOption($option, $value)
		{
			$this->options[$option] = $value;
		}

	}