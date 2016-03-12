<?php
/* Mini Parser BbCodes to Html - v1.3 by Sedo - CC by 3.0*/
class Sedo_TinyQuattro_Helper_MiniParser
{
	/**
	 * Parser configuration
	 */
	protected $_parserOpeningCharacter = '{';
	protected $_parserOpeningCharacterRegex = '{';
	protected $_parserClosingCharacter = '}';
	protected $_parserClosingCharacterRegex = '}';
	protected $_parserDepthLimit = 50;
	protected $_htmlspecialcharsForContent = true;
	protected $_htmlspecialcharsForOptions = true;
	protected $_renderStates = array();
	protected $_checkClosingTag = false; 		// Check if a closing tag exists for the tag being processed
	protected $_preventHtmlBreak = false;		// For private use (protect Bb Code and avoid page break) 
	protected $_externalFormatter = false; 		// To be valid, it must be an array with the class & the method
	protected $_mergeAdjacentTextNodes = false; 	// Should not do any difference
	protected $_autoRecalibrateStack = false; 	// WIP... not that good
	protected $_nl2br = true;
	protected $_trimTextNodes = true;		//Should be set to false with standard Bb Codes

	/**
	 * Parser debug - bolean values needs to be changed manualy
	 */
	private $__debug_displayStackError = false;
	private $__debug_tagChecker = false;
	private $__debug_parserSpeed = false;

	/**
	 * Original text
	 */
	protected $_text;

	/**
	 * Matches elements given by the preg_split function
	 */
	protected $_matches;

	/**
	 * Tree with tags & text nodes
	 */
	protected $_tree = array();

	/**
	 * Tag ID - use for incrementation
	 */	
	protected $_tagId = 1;

	/**
	 * Tag depth - use for incrementation
	 */
	protected $_depth = 0;

	/**
	 * Tags by depth (array key) - use to get the parent tag
	 */
	protected $_parentTag = array();

	/**
	 * Tags that have been opened
	 */
	protected $_openedTagsStack = array();

	/**
	 * Tags by id (array key) - use to get the tag information
	 */
	protected $_openedTagsInfo = array();

	/**
	 * If some errors are found in the tag structures
	 */
	protected $_structureErrors = 0;

	/**
	 * Master tag - if this parser is used with another one to get nested tags using another format
	 */
	protected $_masterTag;

	/**
	 * Tags rules by tag name (array key)- use to render tags & to check if tag is valid
	 */
	protected $_tagsRules = array();

	/**
	 * Use to disable text nodes if needed
	 */
	protected $_disableTextNodes = false;

	/**
	 * PlainText Mode - Value: false or tagName
	 */
	protected $_plainTextMode = false;

	/**
	 *  Class constructor (will automatically parse the text).
	 *
	 * @param string $text, the text to parse
	 * @param array $tagRules, rules use to render tags. Array key must be the tag name
	 * @param array $masterTag, needed if this parser is used with another one
	 * @param array $parserOptions, parser configuration
	 */
	public function __construct($text, array $tagsRules, $masterTag = array(), $parserOptions = array())
	{
		$this->_tagsRules = array_change_key_case($tagsRules, CASE_LOWER);
		
		if(!empty($masterTag))
		{
			if(empty($masterTag['tag']))
			{
				$masterTag['tag'] = null;
			}

			if(empty($masterTag['option']))
			{
				$masterTag['option'] = null;
			}

			if(empty($masterTag['original']))
			{
				$masterTag['original'] = array(null, null);
			}			
			
			$masterTag['tagId'] = null;
		}
		else
		{
			$masterTag = array('tag' => null, 'option'  => null, 'original' => array(null, null), 'tagId' => null);
		}

		$this->_masterTag = $masterTag;
		$this->_parentTag[0] = $masterTag;
		
		if(!empty($parserOptions))
		{
			if(isset($parserOptions['parserOpeningCharacter']))
			{
				$this->_parserOpeningCharacter = $parserOptions['parserOpeningCharacter'];
				$this->_parserOpeningCharacterRegex = preg_quote($parserOptions['parserOpeningCharacter'], '#');
			}
	
			if(isset($parserOptions['parserClosingCharacter']))
			{
				$this->_parserClosingCharacter = $parserOptions['parserClosingCharacter'];
				$this->_parserClosingCharacterRegex = preg_quote($parserOptions['parserClosingCharacter'], '#');
			}
	
			if(isset($parserOptions['depthLimit']) && is_int($parserOptions['depthLimit']))
			{
				$this->_parserDepthLimit = $parserOptions['depthLimit'];
			}
	
			if(isset($parserOptions['mergeAdjacentTextNodes']))
			{
				$this->_mergeAdjacentTextNodes = $parserOptions['mergeAdjacentTextNodes'];
			}
			
			if(isset($parserOptions['externalFormatter']) && is_array($parserOptions['externalFormatter']))
			{
				$this->_externalFormatter = $parserOptions['externalFormatter'];
			}

			if(isset($parserOptions['htmlspecialcharsForContent']))
			{
				$this->_htmlspecialcharsForContent = $parserOptions['htmlspecialcharsForContent'];
			}

			if(isset($parserOptions['htmlspecialcharsForOptions']))
			{
				$this->_htmlspecialcharsForOptions = $parserOptions['htmlspecialcharsForOptions'];
			}
			
			if(!empty($parserOptions['breakToBr']))
			{
				/* To use with the XenForo Wysiwyg mode */
				$text = preg_replace("#<break />\n#", "<br />", $text);
			}

			if(isset($parserOptions['nl2br']))
			{
				$this->_nl2br = $parserOptions['nl2br'];
			}

			if(isset($parserOptions['renderStates']) && is_array($parserOptions['renderStates']))
			{
				$this->_renderStates = $parserOptions['renderStates'];
			}
			
			if(!empty($parserOptions['checkClosingTag']))
			{
				$this->_checkClosingTag = $parserOptions['checkClosingTag'];
			}
			
			if(!empty($parserOptions['preventHtmlBreak']))
			{
				$this->_preventHtmlBreak = $parserOptions['preventHtmlBreak'];
			}

			if(isset($parserOptions['trimTextNodes']))
			{
				$this->_trimTextNodes = $parserOptions['trimTextNodes'];
			}
		}

		if($this->__debug_parserSpeed)
		{
			$mem = memory_get_usage();
			$startTime = microtime(true);
		}

		$this->_text = $text;
		$this->_matches = $this->_getMatchesFromSplitRegex($text);
		reset($this->_matches);
		$this->_tree = $this->_buildTree();

		if($this->__debug_parserSpeed)
		{
			echo "Time:  " . number_format(( microtime(true) - $startTime), 4) . " Seconds --- Memory: " . (memory_get_usage() - $mem) / (1024 * 1024) . "<br />";
		}
		
		return;
		
		$output = $this->render();
		Zend_Debug::dump($output);
		Zend_Debug::dump($this->_tree);
		Zend_Debug::dump($this->getBasicTree());
	}

	/**
	 *  Tree builder - Thanks to Oliver from weirdog.com
	 *  Source: http://www.weirdog.com/blog/php/un-parser-html-des-plus-leger.html
	 */

	protected function _getMatchesFromSplitRegex($text)
	{
		$poc = $this->_parserOpeningCharacterRegex;
		$pcc = $this->_parserClosingCharacterRegex;

		return preg_split('#'.$poc.'(/?)([^'.$pcc.']*)'.$pcc.'#u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
	}
	
	protected $_stringTreePos = 0;

	protected function _incrementStringPos($length, $correction = 0)
	{
		$this->_stringTreePos += $length+$correction;
		//Zend_Debug::dump($this->_stringTreePos."/".mb_strlen($this->_text, 'UTF-8'));
	}

	protected function _buildTree()
	{
		$nodes = array();
		$i = 0;			

		while (($value = current($this->_matches)) !== FALSE)
		{
			next($this->_matches);
			switch ($i++ % 3)
			{
				case 0:
					/*Text between tags*/
					$this->_pushTextNode($nodes, $value);

					preg_replace('#.#su', '', $value, -1, $length);
					$this->_incrementStringPos($length);
				break;

				case 1:
					/*Tag type: opening or closing*/
					$closing = ($value == '/');
				break;

				case 2:
					if ($closing)
					{
						$tagName = strtolower($value);
						
						//The fallback must use the value and not the tagName to output valid datas from mal formed closing tag (ie: [/quote)
						$fallBack = $this->_parserOpeningCharacter.'/'.$value.$this->_parserClosingCharacter;

						/*Parser Checker*/
						if(!$this->_parseTagChecker($tagName))
						{
							$this->_pushTextNode($nodes, $fallBack);
				
							preg_replace('#.#su', '', $value, -1, $length);
							$this->_incrementStringPos($length, 3);							
							break;
						}

						/*Unexpected Closing Tag Management*/
						$expected = array_pop($this->_openedTagsStack);
						$id = $expected['tagId'];
						
						if ($tagName != $expected['tagName'])
						{
							//TO CHECK: if the nodes must be return to still create a new branch
							if($this->_autoRecalibrateStack)
							{
								$fixedStack = $this->_recalibrateStack($tagName);
								if (!$fixedStack)
								{
									$this->_pushClosingTagFailure($tagName, $expected, $fallBack, $nodes);
									break;
								}
								
								$this->_pushClosingTagSuccess($tagName);
								break;
							}

							$this->_pushClosingTagFailure($tagName, $expected, $fallBack, $nodes);
							break;
						}

						preg_replace('#.#su', '', $value, -1, $length);
						$this->_incrementStringPos($length, 3);

						/* Add information that the tag has been properly closed */						
						if(isset($this->_openedTagsInfo[$id]))
						{
							$this->_openedTagsInfo[$id]['validClosingTag'] = true;
						}
						
						$this->_pushClosingTagSuccess($tagName);

						/***
							Important return nodes to stop the recursive children node and
							get back to the parent element	
						**/
						return $nodes; 
					}
					else
					{
						$missingClosingTagDetected = false;
						$htmlBreakDetected = false;

						/* Option Checker */
						$tagOptionPosition = strpos($value, '=');
					
						if(!$tagOptionPosition)
						{
							$tagName = $value;
							$tagOption = null;
						}
						else
						{
							$tagName = substr($value, 0, $tagOptionPosition);
							$tagOption = substr($value, $tagOptionPosition+1);
							$tagOption = (trim($tagOption)) ? $tagOption : null;
						}

						$tagNameValue = $tagName;
						$tagName = strtolower($tagName);

						$openingFallBack = $this->_parserOpeningCharacter.$value.$this->_parserClosingCharacter;
						$closingFallBack = $this->_parserOpeningCharacter.'/'.$tagNameValue.$this->_parserClosingCharacter;

						$validTag = $this->_parseTagChecker($tagName, true, $tagOption, 'openingCheck');

						/* Get Wrapping text */
						$getWrappingText = false;

						if($this->_checkClosingTag || $this->_preventHtmlBreak)
						{
							$getWrappingText = true;
						}

						if($getWrappingText)
						{
							list($textBefore, $textAfter) = $this->_getWrappingText();

							/* Missing closing tag detection */
							if($this->_checkClosingTag && $validTag)
							{
								$missingClosingTagDetected = (strpos($textAfter, $closingFallBack) === false);
								//Zend_Debug::dump($textAfter);
							}

							/* Prevent html break detection */
							if($this->_preventHtmlBreak && $validTag && !$missingClosingTagDetected)
							{
								$bbContentEndPos = strpos($textAfter, $closingFallBack);
								$bbcontent = substr($textAfter, 0, $bbContentEndPos);

								if(preg_match('#.*<(?!/)[^>]+?(?<!/)>?$#sui', $bbcontent))
								{
									$htmlBreakDetected = true;
									
								}
							}
						}

						/***
							Increase depth: even if we are not sure the tag will be valid, the depth must be incremented
							to be used with the "parseTagChecker" AND the current tag name must be pushed inside
							the _parentTag array based on this expected depth.

							If the tag fails to be parsed, these changes must be undone
						**/
						$this->_depth++;
						$this->_parentTag[$this->_depth] = array('tag' => $tagName, 'option' => $tagOption, 'tagId' => $this->_tagId);

						/***
							Parser Checker
						**/
						if( !$validTag || $missingClosingTagDetected || $htmlBreakDetected )
						{
							$this->_pushOpeningTagFailure($tagName, $openingFallBack, $nodes);

							preg_replace('#.#su', '', $value, -1, $length);
							$this->_incrementStringPos($length, 2);
							break;
						}

						$tagId = $this->_tagId++;

						/*Add tagName & its ID to the openTags stack*/
						$this->_openedTagsStack[] = array(
							'tagName' => $tagName,
							'tagId' => $tagId,
							'theoricalDepth' => $this->_depth
						);

						/*Check if next nodes must be activated with the current opening tag*/
						$this->_enableDisableTextNodes($tagName, true);

						/*Add tag information to nodes*/
						$tagInfo = array(
							'tagId' => $tagId,
							'tag' => $tagName,
							'option' => ($this->_htmlspecialcharsForOptions) ?
								htmlspecialchars($tagOption) : $tagOption,
							'original' => array(
								0 => $openingFallBack,
								1 => $closingFallBack
							),
							'depth' => $this->_depth,
							'parentTag' => $this->_parentTag[$this->_depth-1]['tag'],
							'parentOption' => $this->_parentTag[$this->_depth-1]['option'],
							'parentTagId' => $this->_parentTag[$this->_depth-1]['tagId']
						);

						preg_replace('#.#su', '', $value, -1, $length);
						$this->_incrementStringPos($length, 2);
						
						$this->_pushOpeningTagSuccess($tagName, $tagInfo);

						/*Here comes the recursive*/
						$tagInfo['children'] = $this->_buildTree();
						$nodes[] = $tagInfo;
					}
				break;
			}
		}
		
		return $nodes;
	}

	protected function _getWrappingText($getTextBefore = false)
	{
		$pos = $this->_stringTreePos;
      		$method = 'regex';
		
		$textBefore = '';
	      	$textAfter = '';
	      	
      		if($method == 'regex')
      		{
      			//a too high number in {} will trigger an error, so let's split it
      			$regexMax = 5000;

      			if($pos <= $regexMax)
      			{
      				$regex = '.{'.$pos.'}';
      			}
      			else
      			{
      				$nRegex = floor($pos/$regexMax);
      				$diffRegex = $pos - ($regexMax*$nRegex);
      				$regex = str_repeat('.{'.$regexMax.'}', $nRegex) . '.{' . $diffRegex . '}';
      			}

      			if(!$getTextBefore)
      			{
      				$regex = '#^'.$regex.'#sui';
      				$textAfter = preg_replace($regex, '', $this->_text);
      			}
      			else
      			{
      				$regex = '#^(?<before>'.$regex.')(?<after>.*)$#sui';
      				$textBefore = '';
      				$textAfter = '';

      				if(preg_match($regex, $this->_text, $match))
      				{
      					$textBefore = $match['before'];
      					$textAfter = $match['after'];
      				}
      			}
      		}
      		elseif($method == 'mb_substr')
      		{
      			/**
      			 * Problems:
      			 * 1) said as very slow (even if I'm not sure the regex method is faster
      			 * 2) not in all php installation
      			 **/
      			
      			if($getTextBefore)
      			{
      				$textBefore = mb_substr($this->_text, 0, $this->_stringTreePos);
      			}
      			  
      			$textAfter = mb_substr($this->_text, $this->_stringTreePos);
      		}
      		elseif($method == 'substr')
      		{
      			/**
      			 * Problem:
      			 * 1) not utf compatible
      			 **/

      			if($getTextBefore)
      			{
      				$textBefore = mb_substr($this->_text, 0, $this->_stringTreePos);
      			}
      			
      			$textAfter = substr($this->_text, $this->_stringTreePos);
      		}
      		
      		return array($textBefore, $textAfter);
	}

	protected function _pushOpeningTagSuccess($tagName, &$tagInfo)
	{
		$tagRules = $this->getTagRules($tagName);
		
		/***
			This is an opening tag, so the tree is going to have a new branch
			The current tag will be the parent of the nested children
		*/
		$this->_parentTag[$this->_depth] = $tagInfo;
		$this->_openedTagsInfo[$this->_tagId-1] = $tagInfo;

		/*Plain Text Mode: enable*/
		if(!empty($tagRules['plainText']))
		{
			$this->_plainTextMode = $tagName;
		}		
	}

	protected function _pushOpeningTagFailure($tagName, $fallback, &$nodes)
	{
		$this->_pushTextNode($nodes, $fallback);
				
		/*Undo modifications previously done for the parseTagChecker*/
		unset($this->_parentTag[$this->_depth]);
		$this->_depth--;
	}
	
	protected function _pushClosingTagSuccess($tagName)
	{
		$tagRules = $this->getTagRules($tagName);
		$this->_depth--;

		/*Plain Text Mode: disable*/
		if(!empty($tagRules['plainText']) && $tagRules['plainText'] == $tagName)
		{
			$this->_plainText = false;
		}

		/*Plain Text Mode: disable*/
		$this->_enableDisableTextNodes($tagName);			
	}

	protected function _pushClosingTagFailure($tagName, $expected, $fallback, &$nodes)
	{
		$this->_structureErrors++;
		$this->_pushTextNode($nodes, $fallback);
		$this->_displayOrHideError($tagName, $expected['tagName']);
	}

	/**
	 *  Check if text nodes must be enable or disable
	 */
	protected function _enableDisableTextNodes($tagName, $isOpening = false)
	{
		if(!$this->_isValidTag($tagName))
		{
			$this->_disableTextNodes = false;
			return;
		}
		
		$tagRules = $this->getTagRules($tagName);
		if(!empty($tagRules['disableTextNodes']))
		{
			$disableTextNodes = $tagRules['disableTextNodes'];

			if(	is_string($disableTextNodes) && $disableTextNodes == 'insideContent' 
				||
				is_bool($disableTextNodes) && $disableTextNodes === true
			)
			{
				/***
					A short explanation here:
					The disable Text Nodes option is activated. This means the whole 
					tag content will not get any text nodes but still can have
					tag nodes.
					
					When an opening tag is successfully parsed, this will be activated and
					when a closing tag is successfully parsed it will be disabled
					
				**/
				$this->_disableTextNodes = ($isOpening) ? true : false;
			}
			
			if(is_string($disableTextNodes) && $disableTextNodes == 'afterClosing')
			{
				/***
					This time the text nodes will only be disabled after the closing tag
				**/
				$this->_disableTextNodes = ($isOpening) ? false : true;
			}

			if(is_string($disableTextNodes) && $disableTextNodes == 'inAndAfter')
			{
				/***
					This behaviour is quite special, it will mix the two above functions
				**/
				$this->_disableTextNodes = true;
			}			
		}
		else
		{
			$this->_disableTextNodes = false;		
		}
	}

	/**
	 *  Add a text node to the tree
	 */
	protected function _pushTextNode(&$nodes, $text)
	{
		if($this->_disableTextNodes == true)
		{
			return;
		}
		
		if (trim($text) == '' && $this->_trimTextNodes == true)
		{
			return;
		}
		
		if(preg_match('#(.*)(<break-start />)(.*)\2\n(.*)#siu', $text, $patch))
		{
			//Fix for lists - XenForo Wysiwyg Parser
			$text = $patch[1].$patch[3].nl2br($patch[4]);
		}
		else
		{
			if($this->_nl2br)
			{
				$text = nl2br($text);
			}
		}
		
		if(!$this->_mergeAdjacentTextNodes)
		{
			$nodes[] = $text;
			return;
		}
		
		$lastNode = end($nodes);
		reset($nodes);
							
		if(!empty($lastNode) && is_string($lastNode))
		{
			$lastNode = array_pop($nodes);
			$lastNode .=$text;
			$nodes[] = $lastNode;
		}
		else
		{
			$nodes[] = $text;
		}
	}

	/**
	 *  Check if the current tag must be parsed (return: true) or added as a text node (return: false)
	 */
	protected function _parseTagChecker($tagName, $isOpeningTag = false, $tagOption = null, $method = null)
	{
		$depth = $this->_depth;
		$tagRules = $this->getTagRules($tagName);
		list($parentTagName, $parentTagRules) = $this->_getParentTagNameAndRules($depth-1);

		/*Tags checker*/
		if(!$this->_isValidTag($tagName))
		{
			return false;
		}

		/*Depth limit protection*/
		if($depth > $this->_parserDepthLimit)
		{
			return false;
		}
	
		/*Plain Text Mode: check*/
		if($this->_plainTextMode && $this->_plainTextMode != $tagName)
		{
			return false;
		}

		/*Tags options checker*/
		if(!empty($tagRules['compulsoryOption']))
		{
			if($tagOption == null)
			{
				return false;
			}
			
			if(!empty($tagRules['compulsoryOptionRegex']) && !preg_match($tagInfo['compulsoryOptionRegex'], $tagOption))
			{
				return false;
			}
		}

		/***
		 * Check for opening tag
		 * The depth has not been processed at this point, so skip all options related to parent/children elements
		 **/
		 
		if($method == 'openingCheck')
		{
			return true;
		}

		/*Debug*/
		if($this->__debug_tagChecker)
		{
			$debug_message = $tagName;
			$debug_message.= ($isOpeningTag) ? 
				" - parent: $parentTagName | isOpeningTag (depth: $depth)" 
				:
				" - parent: $parentTagName | isClosingTag (depth: $depth)";
		}

		/*Parents based permissions*/
		if(isset($tagRules['allowedParents']))
		{
			$param = $tagRules['allowedParents'];

			if(	(is_bool($param) && $param !== true)
				||
				(is_string($param) && $param == 'none')
				||
				(is_array($param) && !in_array($parentTagName, $param))
			)
			{
				$allowedParents = (!is_array($param)) ? '{none}' : implode(', ', $param);
				if($this->__debug_tagChecker)
				{
					$debug_message.= "\t$tagName parent tag is $parentTagName. ";
					$debug_message.= "The only allowed parent tags are: $allowedParents";
					var_dump($debug_message);
				}
				$this->_structureErrors++;
				return false;
			}
		}

		if(isset($tagRules['forbiddenParents']))
		{
			$param = $tagRules['forbiddenParents'];
			if(	(is_bool($param) && $param === true)
				||
				(is_string($param) && $param == 'all')			
				||
				(is_array($param) && in_array($parentTagName, $param))
			)
			{
				$forbiddenParents = (!is_array($param)) ? 'all' : implode(', ', $param);
				if($this->__debug_tagChecker)
				{
					$debug_message.= "\t$tagName parent tag is $parentTagName. ";
					$debug_message.= "The forbidden parent tags are: $forbiddenParents";
					var_dump($debug_message);
				}
				$this->_structureErrors++;
				return false;
			}
		}

		/*Children based permissions*/
		if(isset($parentTagRules['allowedChildren']))
		{
			$param = $parentTagRules['allowedChildren'];
			if(	(is_bool($param) && $param !== true)
				||
				(is_string($param) && $param == 'none')
				||
				(is_array($param) && !in_array($tagName, $param))
			)
			{
				$allowedChildren = (!is_array($param)) ? '{none}' : implode(', ', $param);
				if($this->__debug_tagChecker)
				{
					$debug_message.= "\t$tagName parent tag is $parentTagName. ";
					$debug_message.= "This parent tag only allows these children tags: $allowedChildren";
					var_dump($debug_message);
				}
				$this->_structureErrors++;
				return false;
			}
		}

		if(isset($parentTagRules['forbiddenChildren']))
		{
			$param = $parentTagRules['forbiddenChildren'];
			if(	(is_bool($param) && $param === true)
				||
				(is_string($param) && $param == 'all')
				||
				(is_array($param) && in_array($tagName, $param))
			)
			{
				$forbiddenChildren = (!is_array($param)) ? 'all' : implode(', ', $param);
				if($this->__debug_tagChecker)
				{
					$debug_message.= "\t$tagName parent tag is $parentTagName. ";
					$debug_message.= "This parent tag doesn't allow these children tags: $forbiddenChildren";
					var_dump($debug_message);					
				}
				$this->_structureErrors++;
				return false;
			}
		}

		if($this->__debug_tagChecker)
		{
			var_dump($debug_message);					
		}	

		return true;
	}

	/**
	 * Check if the tag is in the list
	 */
	protected function _isValidTag($tagName)
	{
		return array_key_exists($tagName, $this->_tagsRules);
	}

	/**
	 * Get the parent tag name & rules
	 * Returns an array with both values
	 */
	protected function _getParentTagNameAndRules($depth)
	{
		if(isset($this->_parentTag[$depth]))
		{
			$parentTagName = $this->_parentTag[$depth]['tag'];
			$parentTagRules = $this->getTagRules($parentTagName);
			
			return array($parentTagName, $parentTagRules);
		}
		
		return array(null, null);
	}

	/**
	 * Not that good, ignore this at the moment
	 */
	protected function _recalibrateStack($tagName)
	{
		$correctedStack = $this->_openedTagsStack;
		
		if (!$this->_openedTagsStack)
		{
			return false;
		}

		$i = 1;
		while ($el = array_pop($correctedStack))
		{
			if ($el['tagName'] == $tagName)
			{
				$this->_openedTagsStack = $correctedStack;
				return true;
			}
			$i++;
		}

		return false;
	}

	/**
	 * For debuging purpose
	 */
	protected function _displayOrHideError($tagName, $expectedTag)
	{
		if($this->__debug_displayStackError)
		{
			printf('unexpected closing tag "%s", should be "%s"', $tagName, $expectedTag);
		}
	}

	/**
	 * Get all tags rules
	 */
	public function getTagsRules()
	{
		return (!empty($this->_tagsRules)) ? $this->_tagsRules : false;
	}

	/**
	 * Get one tag rules
	 */
	public function getTagRules($tagName)
	{
		return (isset($this->_tagsRules[$tagName])) ? $this->_tagsRules[$tagName] : null;
	}

	/**
	 * Get Master tag
	 */	
	public function getMasterTag()
	{
		return $this->_masterTag;
	}

	/**
	 * Get tag info from stack by its id
	 */
	public function getTagInfoFromStackById($id)
	{
		return (!empty($this->_openedTagsInfo[$id])) ? $this->_openedTagsInfo[$id] : null;
	}

	/**
	 * Get the tree
	 *
	 * Output: parsed text tree
	 */
	public function getTree()
	{
		return $this->_tree;
	}

	/**
	 * Get a basic representation of the tree (useful to debug)
	 * @param bool $showTextNodes, to display text nodes (default is false)
	 * @param bool $textMode, to ouput a string easy to read of the tree
	 *	 
	 * Output: array || string, it depends on the $textMode parameter
	 */
	public function getBasicTree($showTextNodes = false, $textMode = false)
	{
		$simplifiedTree = $this->_simplifyTree($this->_tree, $showTextNodes, $textMode);

		if(!$textMode)
		{
			return 	$simplifiedTree;
		}
		
		return $this->_simplifiedTreeOutput;
	}

	protected $_simplifiedTreeOutput = '';

	protected function _simplifyTree(array $tree, $showTextNodes, $textMode, $_depth = 0)
	{
		foreach($tree as $n => &$branch) {
			if(!is_array($branch)) {
				if(!$showTextNodes){
					unset($tree[$n]);
				}else{
					$tab = str_repeat("--", $_depth+1);
					$this->_simplifiedTreeOutput .= "{$tab}Text Node: {$branch}\r\n";
				}
				continue;
			}
			
			$_tag = ''; $_tagId = ''; $_depth = '';

			foreach($branch as $key => $value) {
				if(!in_array($key, array('tag', 'tagId', 'depth', 'children')))	{
					unset($branch[$key]);
					continue;
				} else	{
					switch ($key){
						case 'tag': 	$_tag = $value; 	break;
						case 'tagId':	$_tagId = $value;	break;
						case 'depth':	$_depth = $value;	break;
					}
				}
			}

			$tab = str_repeat("--", $_depth+1);
			$this->_simplifiedTreeOutput .= "{$tab}{$_tag} (depth: {$_depth} - id: {$_tagId})\r\n";

			if(isset($branch['children'])) {
				$onlyTextNodes = false;
				$branch['children'] = $this->_simplifyTree($branch['children'], $showTextNodes, $textMode, $_depth);
			}
		}

		return $tree;		
	}

	/**
	 * Renderer Mode => Fixer
	 * Use the 'fixer' to check the Bb Code tags structure after a conversion from Html to Bb Code
	 */
	protected $_fixerMode = false;
	
	public function isfixerModeEnable()
	{
		return ($this->_fixerMode);
	}

	public function fixer()
	{
		$this->_fixerMode = true;
		$render = $this->render();
		$this->_fixerMode = false;
		return $render;
	}

	/**
	 * Formatter - unfold tree and return text with formatted Bb Codes
	 */
	public function render()
	{
		$rendererStates = array(
			'miniParser' => true,
			'miniParserTagRules' => $this->getTagsRules(),
			'miniParserFormatter' => ($this->_externalFormatter) ? false : true,
			'miniParserNoHtmlspecialchars' => (!$this->_htmlspecialcharsForContent) ? true : false
		);

		if(!empty($this->_renderStates))
		{
			$rendererStates = array_merge($rendererStates, $this->_renderStates);
		}
		
		if($this->_externalFormatter)
		{
			list($class, $method) = $this->_externalFormatter;
			$output = call_user_func_array(array($class, $method), array($this->_tree, $rendererStates));
			return $output;
		}

		return $this->renderTree($this->_tree, $rendererStates);
	}
	
	public function renderTree(array $tree, array &$rendererStates)
	{
		$output = $this->renderSubTree($tree, $rendererStates);

		return trim($output);
	}

	public function renderSubTree(array $tree, array &$rendererStates)
	{
		$output = '';

		foreach ($tree AS $element)
		{
			$rendererStates['currentTree'] = array_values($tree);
			$output .= $this->renderTreeElement($element, $rendererStates);
		}

		return $output;
	}

	public function renderTreeElement($element, array &$rendererStates)
	{
		if (is_array($element))
		{
			return $this->renderTag($element, $rendererStates);
		}
		else
		{
			return $this->renderString($element, $rendererStates);
		}
	}
	
	public function renderString($string, array $rendererStates)
	{
		return $this->filterString($string, $rendererStates);
	}

	public function renderTag(array $element, array &$rendererStates)
	{
		$tagName = $element['tag'];
		$tagRules = $this->getTagRules($tagName, $rendererStates);

		$tagId = $element['tagId'];
		$validTag = true;

		if(isset($this->_openedTagsInfo[$tagId]) && empty($this->_openedTagsInfo[$tagId]['validClosingTag']))
		{
			$validTag = false;
			$rendererStates['invalidClosingTag'] = true;
		}

		if ((!$tagRules && !$this->isfixerModeEnable()) || !$validTag)
		{
			return $this->renderInvalidTag($element, $rendererStates);
		}
		
		if($this->isfixerModeEnable())
		{
			return $this->fixerValidTag($tagRules, $element, $rendererStates);		
		}
		
		return $this->renderValidTag($tagRules, $element, $rendererStates);
	}

	public function renderValidTag(array $tagRules, array $tag, array &$rendererStates)
	{
		if (!empty($tagRules['callback']))
		{
			list($class, $method) = $tagRules['callback'];
			return call_user_func_array(array($class, $method), array($tag, $rendererStates, $this));
		}
		else if (!empty($tagRules['replace']))
		{
			if(empty($tagRules['replaceContent']))
			{
				$text = $this->renderSubTree($tag['children'], $rendererStates);
			}
			else
			{
				$text = $tagRules['replaceContent'];
			}
			
			$option = $this->filterString($tag['option'], $rendererStates);

			list($prepend, $append) = $tagInfo['replace'];
			return $this->wrapInHtml($prepend, $append, $text, $option);
		}
		else if(!empty($tagRules['stringReplace']))
		{
			return $tagRules['stringReplace'];
		}

		return $this->renderInvalidTag($tag);
	}

	public function renderInvalidTag(array $tag, array &$rendererStates)
	{
		$prepend = '';
		$append = '';

		if (!empty($tag['original']) && is_array($tag['original']))
		{
			list($prepend, $append) = $tag['original'];
			$prepend = $this->filterString($prepend, $rendererStates);
			
			if(empty($rendererStates['invalidClosingTag']))
			{
				$append = $this->filterString($append, $rendererStates);
			}
		}

		return $this->wrapInHtml(
			$prepend, 
			$append,
			$this->renderSubTree($tag['children'], $rendererStates)
		); 
	}		

	public function fixerValidTag(array $tagRules, array $tag, array &$rendererStates)
	{
		$option = $this->filterString($tag['option'], $rendererStates);

		$tagName = $tag['tag'];
		$tagId = $tag['tagId'];

		$prevMerge = $nextMerge = false;

		/*Check if current and parent are the same - [b]test[b]test 2[/b]test3[/b]*/
		$parentTagName = $tag['parentTag'];
		$parentTagOption = $this->filterString($tag['parentOption'], $rendererStates);

		if($tagName == $parentTagName && $option == $parentTagOption)
		{
			$prevMerge = $nextMerge = true;
		}

		/*Check if siblings are the same (compatible blank space nodes) - [b]test[b] [b]test 2[/b]*/
		$contextIt = null;
		if((!$prevMerge && !$nextMerge))
		{
			$contextIt = new Sedo_TinyQuattro_Helper_MiniIterator($rendererStates['currentTree'], $tag);
			list($prevMergeWip, $nextMergeWip) = $contextIt->sibblingRoutine();

			if($prevMergeWip || $nextMergeWip) $contextIt->setModifiedSiblings();
			if($prevMergeWip) $prevMerge = true;
			if($nextMergeWip) $nextMerge = true;
		}

		/**
		 * Check if parent siblings have been modified. If it is:
		 * > check if the current tag is a uniq tag in the tree or if it is the last tag, then look forward if the next sibling parent tag has a similar first child tag
		 * > check if the current tag is a uniq tag in the tree or if it is the first tag, then look backward if the prev sibling parent tag has a similar last child tag
		 * Ex: [font=Times New Roman][b][i]word1[/i][/b][/font] [font=Times New Roman][b][i] word2[/i][/b][/font]
		 **/

		$parentDepth = $tag['depth'] - 1;
		if((!$prevMerge && !$nextMerge) && isset($rendererStates['parentTreesIt'], $rendererStates['parentTreesIt'][$parentDepth]))
		{
			$wipDepth = $parentDepth;

			do{
				$parentContextIt = $rendererStates['parentTreesIt'][$wipDepth];
				list($prevMergeWip, $nextMergeWip) = $this->_detectNestedIdenticalBbCodePattern($parentContextIt, $tag, $wipDepth);

				if($prevMergeWip || $nextMergeWip) $contextIt->setModifiedSiblings(); // do not forget to do it ;)
				if($prevMergeWip) $prevMerge = true;
				if($nextMergeWip) $nextMerge = true;

				if($prevMerge && $nextMerge) break;
				$wipDepth--;
			}
			while(
				isset($rendererStates['parentTreesIt'][$wipDepth])
				&& $rendererStates['parentTreesIt'][$wipDepth]->isModifiedSiblings()
			);
			
			unset($wipDepth);
		}
		
		if($prevMerge && $nextMerge)
		{
			$prepend = '';
			$append = '';
			$option = null;
		}
		elseif($prevMerge)
		{
			$prepend = '';
			$append = "[/{$tagName}]";
		}
		elseif($nextMerge)
		{
			$prepend = ($option) ? "[{$tagName}={$option}]" : "[{$tagName}]";
			$append = '';
		}
		else
		{
			$prepend = ($option) ? "[{$tagName}={$option}]" : "[{$tagName}]";
			$append = "[/{$tagName}]";		
		}

		if($contextIt != null)
		{
			//Why save iterator and not simply branch? => iterator keeps the tag position
			$rendererStates['parentTreesIt'][$tag['depth']] = $contextIt;
		}

		$text = $this->renderSubTree($tag['children'], $rendererStates);

		return $prepend.$text.$append;
	}

	protected function _detectNestedIdenticalBbCodePattern(Sedo_TinyQuattro_Helper_MiniIterator $parentContextIt, array &$tag, $parentDepth = 1)
	{
		$tagName = $tag['tag'];
		$tagId = $tag['tagId'];
		$option = $tag['option'];
		$parentTagId = $tag['parentTagId'];
		
		$parentDepth = ($parentDepth  <= 0) ?  1 : $parentDepth;
		$recursiveLevel = $tag['depth'] - $parentDepth;
		
		$prevMerge = $nextMerge = false;
		$currentTree = array_filter($parentContextIt->getChildrenFromTag());

		$contextItNoBlank = new Sedo_TinyQuattro_Helper_MiniIterator($currentTree, $tag);
		list($currentIsUniq, $currentIsFirst, $currentIsLast) = $contextItNoBlank->checkCurrentPositions();

		if($currentIsUniq || $currentIsLast)
		{
			$parentContextIt->rewind(); // should not be needed "inside the box"
			$parentContextIt->prevNoBlank();

			$childrenFromPrevSiblingParent = $parentContextIt->getNestedChildrenByRecursiveLevel($recursiveLevel);
			$childrenFromPrevSiblingParentIt = new Sedo_TinyQuattro_Helper_MiniIterator($childrenFromPrevSiblingParent);
			$firstChildTagFromPrevSibling = $childrenFromPrevSiblingParentIt->getCurrentTag();
			
			if($firstChildTagFromPrevSibling == $tagName)
			{
				$firstChildOptionFromPrevSibling = $childrenFromPrevSiblingParentIt->getCurrentOption();
				$prevMerge = (empty($tagOption)) ? true : ($tagOption == $firstChildOptionFromPrevSibling);
			}
		}

		if($currentIsUniq || $currentIsFirst)
		{
			$parentContextIt->rewind();
			$parentContextIt->nextNoBlank();
	
			$childrenFromNextSiblingParent = $parentContextIt->getNestedChildrenByRecursiveLevel($recursiveLevel);
			$childrenFromNextSiblingParentIt = new Sedo_TinyQuattro_Helper_MiniIterator($childrenFromNextSiblingParent);
			$childrenFromNextSiblingParentIt->last();
			$lastChildTagFromNextSibling = $childrenFromNextSiblingParentIt->getCurrentTag();

			if($lastChildTagFromNextSibling == $tagName)
			{
				$lastChildOptionFromNextSibling = $childrenFromNextSiblingParentIt->getCurrentOption();
				$nextMerge = (empty($tagOption)) ? true : ($tagOption == $lastChildOptionFromNextSibling);
			}				
		}
		
		return array($prevMerge, $nextMerge);
	}

	public function filterString($string, array &$rendererStates)
	{
		if($rendererStates['miniParserNoHtmlspecialchars'])
		{
			return $string;
		}
		
		$string = htmlspecialchars($string);
	}

	public function wrapInHtml($prepend, $append, $text, $option = null)
	{
		if ($option === null)
		{
			return $prepend . $text . $append;
		}
		else
		{
			return sprintf($prepend, $option) . $text . sprintf($append, $option);
		}
	}
}


/**
 * Iterator Class for tags navigation
 */
class Sedo_TinyQuattro_Helper_MiniIterator implements Iterator
{
	private $tree = array();
	private $totalTreeEl = 0;
	private $index = 0;
	private $calibratedIndex = 0;
	private $refTag = null;
	private $refOption = null;
	private $refParentTagId = null;
	private $savedIndex = 0;

	public function __construct(array $tree, array $refTagData = array())
	{
		$refTag = (isset($refTagData['tag'])) ? $refTagData['tag'] : null;
		$refOption = (isset($refTagData['option'])) ? $refTagData['option'] : null;	
		$refTagId = (isset($refTagData['tagId'])) ? $refTagData['tagId'] : null;
		$refParentTagId = (isset($refTagData['parentTagId'])) ? $refTagData['parentTagId'] : null;

		$tree = array_values($tree); //better to put it here to be sure the array index is clean
		$this->tree = $tree;
		$this->totalTreeEl  = count($tree);

		$this->refTag = $refTag;
		$this->refOption = $refOption;
		$this->refParentTagId = $refParentTagId;
		
		if($refTagId)
		{
			foreach($tree as $i => $arr)
			{
				if(!isset($arr['tagId']))
				{
					continue;
				}
				
				if($arr['tagId'] == $refTagId)
				{
					$this->index = $i;
					$this->calibratedIndex = $i;
					break;
				}
			}
		}
		else
		{
			$this->index = 0;
		}
	}

	public function current()
	{
		if(!isset($this->tree[$this->index]))
		{
			return null;
		}
	
		return $this->tree[$this->index];
	}

	public function save()
	{
		$this->savedIndex = $this->index;
	}

	public function restore()
	{
		$this->index = $this->savedIndex;
	}

	public function getCurrentTag()
	{
		$current = $this->current();
		if(isset($current['tag']))
		{
			return $current['tag'];
		}
		
		return null;
	}

	public function getCurrentOption()
	{
		$current = $this->current();
		if(isset($current['option']))
		{
			return $current['option'];
		}
		
		return null;
	}
	
	public function getChildrenFromTag()
	{
		$current = $this->current();
		
		if(isset($current['children']))
		{
			return $current['children'];
		}
		return array();
	}
	
	public function moveByDelta($i = 0)
	{
		if($i >= 0)
		{
			while($x != 0) {
				$this->next();
				$i--;
			}
		}
		else
		{
			while($x != 0) {
				$this->next();
				$i++;
			}		
		}
	}

	public function moveByDeltaOrigin($i = 0)
	{
		$this->rewind();
		$this->moveByDelta($i);
	}
	
	public function isCurrentBlankString()
	{
		$current = $this->current();

		if(is_string($current) && trim($current) == '')
		{
			return true;
		}
		
		return false;
	}
	
	public function isCurrentTag()
	{
		$current = $this->current();
		if(is_array($current) && !empty($current))
		{
			return true;
		}
		
		return false;
	}

	public function first()
	{
		$this->index = 0;
	}

	public function last()
	{
		$this->index = $this->totalTreeEl - 1;
	}

	public function prev()
	{
		$this->index--;		
	}

	public function next()
	{
		$this->index++;
	}

	public function prevTag()
	{
		$maxIndex = $this->totalTreeEl-1;
		$this->prev();
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		while(!$this->isCurrentTag && $this->isCurrentBlankString() && $this->isValidIndex())
		{
			$this->prev();
		}
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		return $this->current();
	}


	public function nextTag()
	{
		$maxIndex = $this->totalTreeEl-1;

		$this->next();

		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		while(!$this->isCurrentTag && $this->isCurrentBlankString() && $this->isValidIndex())
		{
			$this->next();
		}
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		return $this->current();
	}

	public function prevNoBlank()
	{
		$maxIndex = $this->totalTreeEl-1;
		$this->prev();
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		while($this->isCurrentBlankString() && $this->isValidIndex())
		{
			$this->prev();
		}
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		return $this->current();
	}


	public function nextNoBlank()
	{
		$maxIndex = $this->totalTreeEl-1;
		$this->next();
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		while($this->isCurrentBlankString() && $this->isValidIndex())
		{
			$this->next();
		}
		
		if(!$this->isValidIndex())
		{
			return null;
		}
		
		return $this->current();
	}

	public function key()
	{
		return $this->index;
	}

	public function valid()
	{
		return isset($this->tree[$this->key()]);
	}

	public function isValidIndex()
	{
		$maxIndex = $this->totalTreeEl-1;
		return ($this->index >= 0 && $this->index <= $maxIndex);
	}

	public function rewind()
	{
		$this->index = $this->calibratedIndex;
	}

	public function reverse()
	{
		$this->tree = array_reverse($this->tree);
		$this->rewind();
	}

	/*Modified Siblings functions*/
	protected $_modifiedSiblings = false;
	public function setModifiedSiblings()
	{
		$this->_modifiedSiblings = true;
	}
	
	public function isModifiedSiblings()
	{
		return $this->_modifiedSiblings;
	}

	/*Check current tag position: is is first/last/uniq child?*/
	public function checkCurrentPositions()
	{
		$index = $this->index;
		$lastIndex = $this->totalTreeEl-1;
		
		$isFirstPos = ($index == 0);
		$isLastPos = ($index == $lastIndex);
		$isUniq = ($isFirstPos && $isLastPos);
		
		return array($isUniq, $isFirstPos, $isLastPos);
	}

	/*Detect identical sibblings routine*/	
	public function sibblingRoutine()
	{
		$prevMerge = false;
		$nextMerge = false;

		$prevIsBlank = false;
		$nextIsBlank = false;

		$this->prev();
		if($this->currentIsSimilarToRef())
		{
			$prevMerge = true;
		}
		elseif($this->isCurrentBlankString())
		{
			$prevIsBlank = true;
		}
			
		if($prevIsBlank)
		{
			$this->prev();

			if($this->currentIsSimilarToRef('prevBlank'))
			{
				$prevMerge = true;
			}
		}
			
		$this->rewind();
		$this->next();
		
		if($this->currentIsSimilarToRef())
		{
			$nextMerge = true;
		}
		elseif($this->isCurrentBlankString())
		{
			$nextIsBlank = true;			
		}

		if($nextIsBlank)
		{
			$this->next();
			if($this->currentIsSimilarToRef('nextBlank'))
			{
				$nextMerge = true;
			}
		}
		
		$this->rewind();
		
		return array($prevMerge, $nextMerge);
	}

	public function currentIsSimilarToRef($debug = null)
	{
		$current = $this->current();

		if(!isset($current['tag']))
		{
			return false;
		}

		if($current['tag'] != $this->refTag)
		{
			return false;
		}
		
		if(empty($this->refOption))
		{
			return true;
		}
		
		if(empty($current['option']))
		{
			return false;	
		}
		
		if($current['option'] == $this->refOption)
		{
			return true;
		}
	}
	
	/*Recursive emulation only for tags - recursive base: first child tag*/	
	public function getNestedChildrenByRecursiveLevel($recursiveLevel, array $children = null)
	{
		if($children == null)
		{
			//First level, so $i must start at 1, array_filter to clean empty and array_values to reindex
			$children = array_values(array_filter($this->getChildrenFromTag()));
		}

		for($children, $i = 1; $i < $recursiveLevel; $i++){ 
			if(empty($children) || !isset($children[0], $children[0]['children']))
			{
				return array();
			}
			
			$children = array_values(array_filter($children[0]['children']));
			
		}

		if(empty($children) || !is_array($children))
		{
			return array();
		}
				
		return $children;	
	}
	
	/*Get only tags from tree => which are designed as array and not string*/
	private $TagsTreeStack = array();
	public function getOnlyTagsTree($tree = null, $keyTree = 'last')
	{
		if(!$tree)
		{	
			$keyTree = 'itTree';
			$tree = $this->tree;
		}
		
		if(!isset($this->TagsTreeStack[$keyTree]))
		{
			$this->TagsTreeStack[$keyTree] = array_filter($tree, 'is_array');
		}

		return $this->TagsTreeStack[$keyTree];
	}

	/*Get only not blank elements from tree*/
	private $TreeWithoutBlanks = array();
	public function getTreeWithoutBlanks()
	{
		return array_filter($tree);
	}
	
	public function getParentTagId()
	{
		return $this->refParentTagId;
	}
}
//Source: http://www.weirdog.com/blog/php/un-parser-html-des-plus-leger.html
//Zend_Debug::dump($abc);