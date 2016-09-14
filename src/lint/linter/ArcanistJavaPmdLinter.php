<?php

/*
 * Parser inspired from: https://secure.phabricator.com/D14632
 */
class ArcanistJavaPmdLinter extends ArcanistLinter {
  
  const DOWNLOAD_FILENAME = 'pmd-bin-{{version}}.zip';
  const DOWNLOAD_URL = 'https://github.com/pmd/pmd/releases/download/pmd_releases%2F{{version}}/pmd-bin-{{version}}.zip';

  const DEFAULT_VERSION = '5.5.1';
  const DEFAULT_RULES_PATH = 'pmd.xml';

  private $future;

  private $version = self::DEFAULT_VERSION;
  private $rulesPath = self::DEFAULT_RULES_PATH;

  private $fullyQualifySourcename = false;

  public function getInfoName() {
    return 'Java PMD linter';
  }

  public function getLinterName() {
    return 'PMD';
  }

  public function getInfoURI() {
    return 'http://pmd.github.io/';
  }

  public function getInfoDescription() {
    return pht('Use `%s` to perform static analysis on Java code.', 'pmd');
  }

  public function getLinterConfigurationName() {
    return 'java-pmd';
  }

  public function getPmdRoot() {
    return sys_get_temp_dir() . '/pmd-bin-' . $this->getVersion();
  }
  
  public function getBinaryPath() {
    return $this->getPmdRoot() . '/bin/run.sh';
  }

  public function getVersion() {
    return $this->version;
  }

  public function getRulesPath() {
    return $this->rulesPath;
  }
  
  /* -(  Executing the Linter  )----------------------------------------------- */
  
  public function willLintPaths(array $paths) {
    $this->prepareEnvironment();

    $fileListFilename = $this->buildPmdFileList($paths);
    if (!Filesystem::pathExists($fileListFilename)) {
      throw new Exception(pht('PMD filelist does not exist: ' . $fileListFilename));
    }

    $this->future = new ExecFuture($this->command(), $fileListFilename);
    $this->future->setCWD($this->getProjectRoot());
  }

  public function didLintPaths(array $paths) {
    if (!$this->future) {
      return;
    }

    list($err, $stdout, $stderr) = $this->future->resolve();
    $messages = $this->parseLinterOutput($paths, $err, $stdout, $stderr);

    if ($err && !$messages) {
      // We assume that if the future exits with a non-zero status and we
      // failed to parse any linter messages, then something must've gone wrong
      // during parsing.
      $messages = false;
    }

    if ($messages === false) {
      if ($err) {
        $this->future->resolvex();
      } else {
        throw new Exception(sprintf(
            "%s\n\nSTDOUT\n%s\n\nSTDERR\n%s",
            pht('Linter failed to parse output!'),
            $stdout,
            $stderr));
      }
    }

    foreach ($messages as $message) {
      $this->addLintMessage($message);
    }
  }
  
  protected function parseLinterOutput(array $paths, $err, $stdout, $stderr) {
    if (strlen(trim($stdout)) === 0) {
      return array();
    }

    $dom = new DOMDocument();
    libxml_clear_errors();
    $ok = $dom->loadXML($stdout);

    if ($ok === false) {
      print_r(libxml_get_errors());
      return false;
    }

    $messages = array();
    
    $pmd = $dom->getElementsByTagName('pmd');
    if ($pmd) {
      foreach ($pmd as $pmd_node) {
        $messages = array_merge($messages, $this->parsePmdNodeToLintMessages($pmd_node));
      }
    }

    $cpd = $dom->getElementsByTagName('pmd-cpd');
    if ($cpd) {
      foreach ($cpd as $cpd_node) {
        $messages = array_merge($messages,
          $this->parseCpdNodeToLintMessages($cpd_node, $messages));
      }
    }

    return $messages;
  }

  private function parsePmdNodeToLintMessages($pmd) {
    $messages = array();

    $files = $pmd->getElementsByTagName('file');
    foreach ($files as $file) {
      $violations = $file->getElementsByTagName('violation');
      foreach ($violations as $violation) {
        $message = new ArcanistLintMessage();
        $message->setPath($file->getAttribute('name'));
        $message->setLine($violation->getAttribute('beginline'));
        $message->setCode('PMD');

        // include the ruleset and the rule
        $message->setName($violation->getAttribute('ruleset').
          ': '.$violation->getAttribute('rule'));

        $description = '';
        if (property_exists($violation, 'firstChild')) {
          $first_child = $violation->firstChild;
          if (property_exists($first_child, 'wholeText')) {
            $description = $first_child->wholeText;
          }
        }

        // unescape the XML written out by pmd's XMLRenderer
        if ($description) {
          // these 4 characters use specific XML-escape codes
          $description = str_replace(
            ['&amp;', '&quot;', '&lt;', '&gt;'],
            ['&', '"', '<', '>'],
            $description);

          // everything else is hex-code escaped
          $escaped_chars = array();
          preg_replace_callback(
            '/&#x(?P<hexcode>[a-f|A-F|0-9]+);/',
            array($this, 'callbackReplaceMatchesWithHexcode'),
            $description);

          $message->setDescription($description);
        }

        $column = $violation->getAttribute('begincolumn');
        if ($column) {
          $message->setChar($column);
        }

        $severity = $this->getLintMessageSeverity($violation->getAttribute('priority'));
        $message->setSeverity($severity);
        
        $messages[] = $message;
      }
    }

    return $messages;
  }

  private function parseCpdNodeToLintMessages($cpd_node, array $messages) {
    $dups = $cpd_node->getElementsByTagName('duplication');
    foreach ($dups as $dup) {
      $files = $dup->getElementsByTagName('file');
      $code_nodes = $dup->getElementsByTagName('codefragment');

      $description = pht('Duplicated code locations: ');
      foreach ($files as $file) {
        $description .=
          $file->getAttribute('path').':'.$file->getAttribute('line').', ';
      }

      reset($files);
      foreach ($files as $file) {
        $message = new ArcanistLintMessage();
        $message->setPath($file->getAttribute('path'));
        $message->setLine($file->getAttribute('line'));
        $message->setCode('CPD');
        $message->setName('Copy/Paste Detector');
        $message->setDescription($description);
        $message->setSeverity(ArcanistLintSeverity::SEVERITY_WARNING);

        $messages[] = $message;
      }
    }

    return $messages;
  }

  private function prepareEnvironment() {
    $bin = $this->getBinaryPath();
    
    /*
     * Download and unzip pmd
     */
    if (!Filesystem::pathExists($bin)) {
      $zipPath = str_replace('{{version}}', $this->getVersion(), sys_get_temp_dir() . '/'. self::DOWNLOAD_FILENAME);
      if (!Filesystem::pathExists($zipPath)) {
        $downloadUrl = str_replace('{{version}}', $this->getVersion(), self::DOWNLOAD_URL);
        echo "Downloading PMD.\n"
          . "   Source     : " . $downloadUrl . "\n"
          . "   Destination: " . $zipPath . "\n\n";

        if (file_put_contents($zipPath, fopen($downloadUrl, 'r')) === false) {
          throw new Exception('Failed to download PMD');
        }
      }

      $zipFile = new ZipArchive();
      if ($zipFile->open($zipPath) !== true) {
        throw new Exception('Failed to read PMD zip');
      }

      $zipFile->extractTo(dirname($this->getPmdRoot()));
      $zipFile->close();
    }

    /*
     * Check the run file exists and turn it into a executable one
     */
    if (!Filesystem::pathExists($bin)) {
      throw new Exception(pht('Failed to locate PMD run file.'));
    }

    if (chmod($bin, 0755) === false) {
      throw new Exception(pht('Failed to chmod the PMD run file.'));
    }
    
    if (!Filesystem::binaryExists($bin)) {
      throw new Exception(pht('PMD run file is not a binary.'));
    }
  }

  private function buildPmdFileList(array $paths) {
    $filename = tempnam(sys_get_temp_dir(), 'arcpmd');

    $file = fopen($filename, 'w');
    if ($file === false) {
      throw new Exception(pht('Failed opening temporary file to generate the PMD file list.'));
    }

    $i = 0;
    foreach ($paths as $path) {
      if ($i > 0) {
        fwrite($file, ',');
      }
      fwrite($file, $path);
      $i++; 
    }
    fclose($file);

    return $filename;
  }

  // Docs: http://pmd.github.io/pmd-5.5.1/usage/running.html
  public function command() {
    return $this->getBinaryPath() . ' pmd'
      . ' -language java'
      . ' -filelist %s'
      . ' -format xml'
      . ' -failOnViolation false'
      . ' -rulesets ' . $this->getRulesPath(); 
  }

  private function callbackReplaceMatchesWithHexcode($matches) {
    return $this->convertHexToBin($matches['hexcode']);
  }

  /**
   * This is a replacement for hex2bin() which is only available in PHP 5.4+.
   * Returns the ascii interpretation of a given hexadecimal string.
   *
   * @param $str string  The hexadecimal string to interpret
   *
   * @return string The string of characters represented by the given hex codes
   */
  private function convertHexToBin($str) {
    $sbin = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i += 2) {
      $sbin .= pack('H*', substr($str, $i, 2));
    }
    return $sbin;
  }

  public function getLinterConfigurationOptions() {
    $options = parent::getLinterConfigurationOptions();
    
    $options['version'] = array(
      'type' => 'optional string',
      'help' => pht('The version of pmd to use.')
    );

    $options['rules'] = array(
      'type' => 'optional string',
      'help' => pht('Specify the path to the pmd rules file.')
    );

    return $options;
  }

  public function setLinterConfigurationValue($key, $value) {
    switch ($key) {
      case 'version':
        $this->version = trim($value);
        return;
      case 'rules':
        if (Filesystem::pathExists($value)) {
          $this->rulesPath = $value;
          return;
        }

        $path = Filesystem::resolvePath($value, $this->getProjectRoot());
        if (Filesystem::pathExists($path)) {
          $this->rulesPath = $value;
          return;
        }

        throw new Exception(pht('Could not locale pmd configuration file.'));
    }

    return parent::setLinterConfigurationValue($key, $value);
  }

  public function getLintSeverityMap() {
    return array(
      '1' => ArcanistLintSeverity::SEVERITY_ERROR,
      '2' => ArcanistLintSeverity::SEVERITY_WARNING,
      '3' => ArcanistLintSeverity::SEVERITY_ADVICE,
      '4' => ArcanistLintSeverity::SEVERITY_ADVICE,
      '5' => ArcanistLintSeverity::SEVERITY_ADVICE
    );
  }

}

