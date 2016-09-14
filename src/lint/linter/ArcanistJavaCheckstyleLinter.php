<?php

/*
 * Parser inspired from: https://secure.phabricator.com/D14632
 */
class ArcanistJavaCheckstyleLinter extends ArcanistLinter {

  const DOWNLOAD_URL = 'http://downloads.sourceforge.net/project/checkstyle/checkstyle/{{version}}/checkstyle-{{version}}-all.jar';

  const DEFAULT_VERSION = '7.1.1';
  const DEFAULT_RULES_PATH = 'checkstyle.xml';

  private $future;

  private $version = self::DEFAULT_VERSION;
  private $rulesPath = self::DEFAULT_RULES_PATH;

  private $fullyQualifySourcename = false;

  public function getInfoName() {
    return 'Java checkstyle linter';
  }

  public function getLinterName() {
    return 'CHECKSTYLE';
  }

  public function getInfoURI() {
    return 'http://checkstyle.sourceforge.net';
  }

  public function getInfoDescription() {
    return pht('Use `%s` to perform static analysis on Java code.', 'checkstyle');
  }

  public function getLinterConfigurationName() {
    return 'java-checkstyle';
  }

  public function getJarPath() {
    return sys_get_temp_dir() . '/checkstyle-' . $this->getVersion() . '-all.jar';
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

    $this->future = new ExecFuture($this->command(), $paths);
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
    // Checkstyle's output contains the XML with the data followed by a non-XML line.
    // The next two lines strip out the non-XML text following the XML document.
    $endOfCheckstyle = strpos($stdout, '</checkstyle>');
    $xml = substr($stdout, 0, $endOfCheckstyle + strlen('</checkstyle>'));

    $dom = new DOMDocument();
    libxml_clear_errors();
    $ok = $dom->loadXML($xml);

    if ($ok === false) {
      print_r(libxml_get_errors());
      return false;
    }

    $files = $dom->getElementsByTagName('file');
    $messages = array();
    foreach ($files as $file) {
      $errors = $file->getElementsByTagName('error');
      foreach ($errors as $error) {
        $message = new ArcanistLintMessage();
        $message->setPath($file->getAttribute('name'));
        $message->setLine($error->getAttribute('line'));
        $message->setCode($this->getLinterName());

        // source is the module's fully-qualified classname
        // attempt to simplify it for readability
        $source = $error->getAttribute('source');
        if ($this->fullyQualifySourcename == false) {
          $source = idx(array_slice(explode('.', $source), -1), 0);
        }
        $message->setName('Checkstyle::' . $source);

        // checkstyle's XMLLogger escapes these five characters
        $description = $error->getAttribute('message');
        $description = str_replace(
          ['&lt;', '&gt;', '&apos;', '&quot;', '&amp;'],
          ['<', '>', '\'', '"', '&'],
          $description);
        $message->setDescription($description);

        $column = $error->getAttribute('column');
        if ($column) {
          $message->setChar($column);
        }

        $severity = $this->getLintMessageSeverity($error->getAttribute('severity'));
        $message->setSeverity($severity);
        
        $messages []= $message;
      }
    }

    return $messages;
  }

  private function prepareEnvironment() {
    $jarPath = $this->getJarPath();
    if (Filesystem::pathExists($jarPath)) {
      return;
    }

    $downloadUrl = str_replace('{{version}}', $this->getVersion(), self::DOWNLOAD_URL);
    echo "Downloading the checkstyle.jar.\n"
      . "   Source     : " . $downloadUrl . "\n"
      . "   Destination: " . $jarPath . "\n\n";
    
    if (file_put_contents($jarPath, fopen($downloadUrl, 'r')) === false) {
      throw new Exception('Failed to download checkstyle jar');
    }
  }

  public function command() {
    return 'java -jar ' . $this->getJarPath()
      . ' -c ' . $this->getRulesPath()
      . ' -f xml'
      . ' %Ls';
  }

  public function getLinterConfigurationOptions() {
    $options = parent::getLinterConfigurationOptions();
    
    $options['version'] = array(
      'type' => 'optional string',
      'help' => pht('The version of checkstyle to use.')
    );

    $options['rules'] = array(
      'type' => 'optional string',
      'help' => pht('Specify the path to the checkstyle rules file.')
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

        throw new Exception(pht('Could not locale checkstyle configuration file.'));
    }

    parent::setLinterConfigurationValue($key, $value);
  }

  public function getLintSeverityMap() {
    return array(
      'error'    => ArcanistLintSeverity::SEVERITY_ERROR,
      'warning'  => ArcanistLintSeverity::SEVERITY_WARNING,
      'info'     => ArcanistLintSeverity::SEVERITY_ADVICE,
      'ignore'   => ArcanistLintSeverity::SEVERITY_DISABLED,
    );
  }
}

