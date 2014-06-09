<?php

/**
 * For a given Android project, assuming its resources are API17 based,
 * Process .xml files in 'res/layout' and 'res/values' directories, such that Start/End
 * attributes will be converted to Left/Right ones according to LTR / RTL layout direction.
 * This will enable using them in Pre API17 versions (e.g. API14)
 */
class AndroidResourcesConverter
{
	private $gravityAttributes;
	private $attributesMap;
	private $gravityValuesMap;
	private $reverseHorizontalLayoutChildren;
	private $resourcesDirectory; // Path to the 'res' dir of an Android project
	private $srcSubDir;
	private $dstSubDir;
	private $debug;

	/**
	 * Upgrade from API v14 to v17 resource types (i.e. replace Left/Right with Start/End)
	 * This one is mainly not used
	 */
	const CONVERT_V14_TO_V17 = 14217;
	
	/**
	 * Downgrade from API v14 to v17 resource types (i.e. replace Start/End with Left/Right)
	 */
	const CONVERT_V17_TO_V14 = 17214;
	
	/**
	 * This conversion has two purposes:
	 * 1. If we'll only have layout and layout-v14, then the layout-v14 resource will cloud
	 *    over the layout resources, making the active resources v14 compliant. This is not
	 *    good because in a Right-To-Left layout we reverse the order of child nodes in horizontal layouts.
	 * 2. API v17 layout is not 100% Right-To-Left compliant, and so Right-To-Left TextView/EditView which
	 *    have android:gravity="start" will remain aligned to the left instead of to the right.
	 *    We solve this issue by forcing Left/Right directions instead of Start/End.
	 */
	const CONVERT_V17_TO_V17 = 17217;	

	public function __construct( $debug, $isLeftToRight, $apiDir, $resourcesDirectory ) {
		$this->debug = $debug;
		$this->reverseHorizontalLayoutChildren = (self::getTargetAPI( $apiDir ) < 17);
		$this->resourcesDirectory = $resourcesDirectory;
		$this->init( $isLeftToRight, $apiDir );
	}

	public static function getSourceAPI( $apiDir ) {
		return $apiDir / 1000;
	}

	public static function getTargetAPI( $apiDir ) {
		return $apiDir % 100;
	}

	private function log( $text ) {
		echo "$text\n";
	}

	private function init($isLeftToRight, $apiDir) {
		$this->gravityAttributes = array("android:gravity", "android:layout_gravity");

		switch ( $apiDir ) {
			case self::CONVERT_V14_TO_V17:
				$conversions = array(
						'Left' => ($isLeftToRight ? 'Start' : 'End'),
						'Right' => ($isLeftToRight ? 'End' : 'Start'),
					);
				break;

			case self::CONVERT_V17_TO_V14:
			case self::CONVERT_V17_TO_V17:
				$conversions = array(
						'Start' => ($isLeftToRight ? 'Left' : 'Right'),
						'End' => ($isLeftToRight ? 'Right' : 'Left'),
					);
				break;

			default:
				throw new Exception("API direction {$apiDir} is not supported");
		}

		$rawAttribs = array(
				"android:padding@DIRECTION@",
				"android:drawable@DIRECTION@",
				"android:layout_align@DIRECTION@",
				"android:layout_margin@DIRECTION@",
				"android:layout_alignParent@DIRECTION@",
				"android:layout_to@DIRECTION@Of",
			);

		$this->attributesMap = array();
		foreach ( $rawAttribs as $rawAttrib ) {
			foreach ( $conversions as $srcDirection => $dstDirection ) {
				$srcAttrib = str_replace( '@DIRECTION@', $srcDirection, $rawAttrib );
				$dstAttrib = str_replace( '@DIRECTION@', $dstDirection, $rawAttrib );

				$this->attributesMap[$srcAttrib] = $dstAttrib;
			}
		}

		// Add the lower case version for supporting gravity values
		$this->gravityValuesMap = array();
		foreach ( $conversions as $k => $v ) {
			$this->gravityValuesMap[ strtolower($k) ] = strtolower( $v );
		}
	}

	/**
	 * @returns true if dom is modified, false otherwise.
	 */
	private function convertResourceLayout($node) {
		$isModified = false;

		if ( $node->hasAttributes() ) {
			$attributes = array();
			foreach ( $node->attributes as $attrib ) {
				// Convert any API 17 Start/End attributes to Left/Right attributes.
				// For example, from paddingStart="10dp" to paddingLeft="10dp"
				$name = $attrib->nodeName;
				$value = $attrib->nodeValue;
				if ( array_key_exists( $name, $this->attributesMap ) ) {
					// Change the name of the attribute
					$name = $this->attributesMap[$name];
					$isModified = true;
				}
				elseif ( in_array( $name, $this->gravityAttributes ) ) { // Gravity attribute?
					// Loop directions (e.g. start => left)
					foreach ( $this->gravityValuesMap as $srcDirection => $dstDirection ) {
						if ( strpos( $value, $srcDirection ) !== false ) { // Found a direction that needs replacement?
							// Perform an str_replace in order to cover cases of combinted
							// gravities, e.g. "start|top";
							$value = str_replace( $srcDirection, $dstDirection, $value );
							$isModified = true;
						}
					}
				}

				$attributes[$name] = $value;
			}

			if ( $isModified ) {
				// Remove all attributes
				while ( $node->hasAttributes() ) {
					$node->removeAttribute( $node->attributes->item(0)->nodeName );
				}

				// Re-add the attributes by their original order of appearance
				foreach ( $attributes as $name => $value ) {
					$node->setAttribute( $name, $value );
				}
			}
		}

		if ( $node->hasChildNodes() ) {
			// Iterate all the elements' attributes to find attributes to convert.
			foreach ($node->childNodes as $childNode) {
				$isChildModified = $this->convertResourceLayout($childNode);
				$isModified = $isModified || $isChildModified;
			}

			// Reverse to order of children in horizontal LinearLayout
			if ( $node->hasAttributes()
					&& $node->getAttribute("android:orientation") == "horizontal"
				) {
				// NOTE: Switching dirty flag on always (even if $this->reverseHorizontalLayoutChildren is false)
				//
				// The reason is that if we won't do it, then there's a chance that a layout-v14-ldrtl/file.xml file
				// will be marked as modified because its layout was reversed, while the parallel layout/file.xml
				// file won't. This would lead to the v14 file clouding the layout/file.xml.
				// We switch the flag on in order to create a layout-v17-ldrtl/file.xml which, in ture, will cloud
				// the layout-v14-ldrtl/file.xml (thus overriding with the default layout/file.xml).
				$isModified = true;

				if ( $this->reverseHorizontalLayoutChildren ) {
					$reversedChildNodes = array();
					while ( $node->hasChildNodes() ) {
						$childNode = $node->childNodes->item( 0 );
						array_unshift( $reversedChildNodes, $childNode );
						$node->removeChild( $childNode );
					}

					foreach ($reversedChildNodes as $childNode) {
						$node->appendChild( $childNode );
					}
				}
			}
		}

		return $isModified;
	}

	/**
	 * @returns true if dom is modified, false otherwise.
	 */
	private function convertStyleLayout($dom) {
		$isModified = false;

		$styleNodes = $dom->getElementsByTagName( 'style' );
		foreach ( $styleNodes as $styleNode ) {
			$itemNodes = $styleNode->getElementsByTagName( 'item' );
			foreach ( $itemNodes as $itemNode ) {
				$name = $itemNode->getAttribute('name');
				$value = $itemNode->nodeValue;

				if ( array_key_exists( $name, $this->attributesMap ) ) {
					// Change the name of the attribute
					$newName = $this->attributesMap[$name];
					$itemNode->setAttribute('name', $newName);
					$isModified = true;
				}
				elseif ( in_array( $name, $this->gravityAttributes ) ) { // Gravity attribute?
					// Loop directions (e.g. start => left)
					foreach ( $this->gravityValuesMap as $srcDirection => $dstDirection ) {
						if ( strpos( $value, $srcDirection ) !== false ) { // Found a direction that needs replacement?
							// Perform an str_replace in order to cover cases of combinted
							// gravities, e.g. "start|top";
							$value = str_replace( $srcDirection, $dstDirection, $value );
							$isModified = true;
						}
					}

					if ( $isModified ) {
						$itemNode->nodeValue = $value;
					}
				}
			}
		}

		return $isModified;
	}

	private function convert($dom) {
		if ( $this->srcSubDir == 'layout' ) {
			$isModified = $this->convertResourceLayout( $dom );
		}
		elseif ( $this->srcSubDir == 'values' ) {
			$isModified = $this->convertStyleLayout( $dom );
		}
		else {
			throw new Exception("Unhandled sub directory: {$this->srcSubDir}");
		}

		return $isModified;
	}

	private function convertFile($srcFile, $dstFile) {
		$dom = new DOMDocument();
		$dom->load( $srcFile );
		$dom->formatOutput = true;

		$outputFiles = array();

		if ( $this->debug ) {
			$outputFiles[$dstFile . '.orig'] = $dom->cloneNode(true);
		}

		$isModified = $this->convert( $dom );
		if ( $isModified ) {
			$outputFiles[$dstFile] = $dom->cloneNode(true);
		}
		else {
			unset( $outputFiles[ $dstFile . '.orig' ] );
		}

		foreach ( $outputFiles as $filename => $domNode ) {
			$domNode->save( $filename );
			$this->log( "    Created $filename" );
		}

		return $isModified;
	}

	private $rootSrcDir;
	private $rootDstDir;

	public function convertDirectory( $srcSubDir, $dstSubDir ) {
		$this->srcSubDir = $srcSubDir;
		$this->dstSubDir = $dstSubDir;

		$this->log("Converting $srcSubDir -> $dstSubDir" );
		$this->rootSrcDir = $this->resourcesDirectory ."/" . $srcSubDir;
		$this->rootDstDir = $this->resourcesDirectory ."/" . $dstSubDir;

		if ( ! file_exists( $this->rootSrcDir ) ) {
			self::exitOnError("Source directory doesn't exist: {$this->rootSrcDir}");
		}

		$this->convertDirectoryRecursive( $this->rootSrcDir, $this->rootDstDir );
	}

	private function convertDirectoryRecursive( $srcDir, $dstDir ) {
		if ( ! file_exists( $dstDir ) ) {
			echo "  Creating directory: $dstDir\n";
			mkdir( $dstDir, 0777, true );
		}

		$files = glob( $srcDir . '/*.xml' );
		foreach ( $files as $srcFile ) {
			$subFilePath = substr( $srcFile, strlen( $this->rootSrcDir ) );
			$dstFile = $this->rootDstDir . $subFilePath;
			$isModified = $this->convertFile( $srcFile, $dstFile );
		}

		$subDirs = glob("$srcDir/*", GLOB_ONLYDIR);
		foreach ( $subDirs as $subDir ) {
			$subPath = substr( $srcDir, strlen( $this->rootSrcDir ) );
			$dstDir = $this->rootDstDir . $subPath;
			$this->convertDirectoryRecursive( $subDir, $dstDir );
		}
	}

	private static function exitOnError( $errText, $showSyntax = false ) {
		echo "$errText\n";

		if ( $showSyntax ) {
			echo "Syntax: " . basename(__FILE__) . " [resource path] [clean / build / clean_and_build]\n";
		}

		exit( 1 );
	}

	public static function main( $argv ) {
		$debug = false;

		if ( ! isset($argv[1]) ) {
			self::exitOnError("Resources directory parameter is missing.", true);
		}

		$resourcesDirectory =  $argv[1];
		$doClean = false;
		$doBuild = false;

		$action = isset($argv[2]) ? $argv[2] : '';

		switch ( $action ) {
			case 'clean':
				$doClean = true;
				break;

			case 'build':
				$doBuild = true;
				break;

			case 'clean_and_build':
				$doClean = true;
				$doBuild = true;
				break;

			default:
				self::exitOnError("Action parameter is missing", true);
		}

		$resourceDirs = array(
				'layout',
				'values'
			);

		$layoutDirections = array(
				'ldltr' => true,	// Left to Right resources conversion (ldltr = layout direction left to right)
				'iw' => false,		// Hebrew resources conversion (Pre API 17 version are not familiar with 'ldrtl')
			);

		// Assuming the original resources are API v17 compliant
		$apiDirections = array(
				AndroidResourcesConverter::CONVERT_V17_TO_V14,
				AndroidResourcesConverter::CONVERT_V17_TO_V17
			);

		foreach ( $resourceDirs as $resourceSubDir ) {
			foreach ( $layoutDirections as $langQualifier => $isLeftToRight ) {
				foreach ( $apiDirections as $apiDirection ) {
					// Compose destination sub dir, e.g. "layout-ldrtl-v14", "values-ldltr-v17", etc.
					$dstDir = "$resourceSubDir-$langQualifier-v" . AndroidResourcesConverter::getTargetAPI( $apiDirection );

					if ( $doClean ) {
						// Clean
						$dir = $resourcesDirectory . "/" . $dstDir;
						echo "Cleaning $dir\n";

						if ( file_exists( $dir ) ) {
							foreach ( glob( "$dir/*.xml" ) as $file ) {
								unlink( $file );
							}

							$removed = rmdir( $dir );
							if ( ! $removed ) {
								self::exitOnError("Error: Could not remove $dir");
							}
						}
					}

					if ( $doBuild ) {
						// Build
						$converter = new AndroidResourcesConverter($debug, $isLeftToRight, $apiDirection, $resourcesDirectory);
						$converter->convertDirectory( $resourceSubDir, $dstDir );
					}
				}
			}
		}

		exit( 0 ); // Exit OK
	}
}

// Convert the resources
AndroidResourcesConverter::main( $argv );
