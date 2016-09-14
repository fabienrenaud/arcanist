<?php

class ArcanistJavaFindbugsLinter extends ArcanistLinter {

  const DOWNLOAD_FILENAME = 'findbugs-noUpdateChecks-{{version}}.zip';
  const DOWNLOAD_URL = 'http://downloads.sourceforge.net/project/findbugs/findbugs/{{version}}/findbugs-noUpdateChecks-{{version}}.zip';

  const DEFAULT_VERSION = '3.0.1';
  const DEFAULT_RULES_PATH = 'findbugs.xml';

  private $future;

  private $version = self::DEFAULT_VERSION;
  private $rulesPath = self::DEFAULT_RULES_PATH;

  private $fullyQualifySourcename = false;

  public function getInfoName() {
    return 'Java Findbugs linter';
  }

  public function getLinterName() {
    return 'Findbugs';
  }

  public function getInfoURI() {
    return 'http://findbugs.sourceforge.net';
  }

  public function getInfoDescription() {
    return pht('Use `%s` to perform static analysis on Java code.', 'findbugs');
  }

  public function getLinterConfigurationName() {
    return 'java-findbugs';
  }

  public function getFindbugsRoot() {
    return sys_get_temp_dir() . '/findbugs-' . $this->getVersion();
  }
  
  public function getBinaryPath() {
    return $this->getFindbugsRoot() . '/bin/findbugs';
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

    $fileListFilename = $this->buildFindbugsFileList($paths);
    if (!Filesystem::pathExists($fileListFilename)) {
      throw new Exception(pht('Findbugs filelist does not exist: ' . $fileListFilename));
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
    
    $bugs = $dom->getElementsByTagName('BugInstance');
    foreach ($bugs as $b) {
      $type = $b->getAttribute('type');
      $priority = $b->getAttribute('priority');
      $rank = $b->getAttribute('rank');
      $category = $b->getAttribute('category');
      foreach ($b->childNodes as $child) {
        continue; // tagname does not exist on DOMText node
        if ($child->tagname !== 'SourceLine') {
          continue;
        }

        $sourcePath = $child->getAttribute('sourcepath');
        $startLine = $child->getAttribute('start');
        $endLine = $child->getAttribute('end');

        $message = new ArcanistLintMessage();
        $message->setPath($sourcePath);
        $message->setLine($startLine);
        $message->setCode('Findbugs');
        $message->setName(ucwords($category) . ' - ' . $type);
        $message->setDescription('Description TODO');
        $message->setSeverity($this->getLintMessageSeverity($rank));
          
        $messages[] = $message;
      }
    }

    return $messages;
  }

  private function prepareEnvironment() {
    $bin = $this->getBinaryPath();
    
    /*
     * Download and unzip findbugs
     */
    if (!Filesystem::pathExists($bin)) {
      $zipPath = str_replace('{{version}}', $this->getVersion(), sys_get_temp_dir() . '/' . self::DOWNLOAD_FILENAME);
      if (!Filesystem::pathExists($zipPath)) {
        $downloadUrl = str_replace('{{version}}', $this->getVersion(), self::DOWNLOAD_URL);
        echo "Downloading FindBugs.\n"
          . "   Source     : " . $downloadUrl . "\n"
          . "   Destination: " . $zipPath . "\n\n";

        if (file_put_contents($zipPath, fopen($downloadUrl, 'r')) === false) {
          throw new Exception('Failed to download FindBugs');
        }
      }

      $zipFile = new ZipArchive();
      if ($zipFile->open($zipPath) !== true) {
        throw new Exception('Failed to read FindBugs zip');
      }

      $zipFile->extractTo(dirname($this->getFindbugsRoot()));
      $zipFile->close();
    }
    /*
     * Check the run file exists and turn it into a executable one
     */
    if (!Filesystem::pathExists($bin)) {
      throw new Exception(pht('Failed to locate FindBugs run file.'));
    }

    if (chmod($bin, 0755) === false) {
      throw new Exception(pht('Failed to chmod the FindBugs run file.'));
    }
    

    if (!Filesystem::binaryExists($bin)) {
      throw new Exception(pht('FindBugs run file is not a binary.'));
    }
  }

  private function buildFindbugsFileList(array $paths) {
    $filename = tempnam(sys_get_temp_dir(), 'arcfindbugs');

    $file = fopen($filename, 'w');
    if ($file === false) {
      throw new Exception(pht('Failed opening temporary file to generate the Findbugs file list.'));
    }

    foreach ($paths as $path) {
      $classPath = $this->javaPathToClassPath($path);
      fwrite($file, $classPath . "\n");
    }
    fclose($file);

    return $filename;
  }

  private function javaPathToClassPath($javaPath) {
    $newPath = str_replace('src/main/java/', 'build/classes/main/', $javaPath);
    $newPath = str_replace('.java', '.class', $newPath);
    return $newPath;
  }

  public function command() {
    return $this->getBinaryPath()
      . ' -textui'
      . ' -quiet'
      . ' -xml'
      . ' -analyzeFromFile %s'; 
  }

  public function getLinterConfigurationOptions() {
    $options = parent::getLinterConfigurationOptions();
    
    $options['version'] = array(
      'type' => 'optional string',
      'help' => pht('The version of findbugs to use.')
    );

    $options['rules'] = array(
      'type' => 'optional string',
      'help' => pht('Specify the path to the findbugs rules file.')
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

        throw new Exception(pht('Could not locale findbugs configuration file.'));
    }

    parent::setLinterConfigurationValue($key, $value);
  }

  public function getLintSeverityMap() {
    return array(
      '1' => ArcanistLintSeverity::SEVERITY_ERROR,
      '2' => ArcanistLintSeverity::SEVERITY_WARNING,
      '3' => ArcanistLintSeverity::SEVERITY_ADVICE
    );
  }

}

