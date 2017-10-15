#!/usr/bin/env php
<?php

# Creates a bazel and PHP supported Laravel scaffolding app.

namespace tools\build;

use \Exception;
use \php_check_syntax;

require_once(__DIR__ . '/Options.php');

class Laravel {
  private static $tempFiles;

  private $targetDir;
  private $rootDir;
  private $name;
  private $targetShortPath;
  private $rootNamespace;

  public static function main($args) {
    register_shutdown_function(array('\\Tools\\build\\Laravel', 'cleanUp'));

    $opts = Options::parse($args);
    if ($opts->has('verbose')) {
       $opts->show();
    }

    $rootDir = realpath(__DIR__ . "/../..");
    $outDir = $opts->get_string("out");
    $targetDir = $rootDir . "/{$outDir}";
    $name = basename($targetDir);

    $laravel = new Laravel($targetDir, $name, $rootDir);
    $laravel->createProject();
  }

  private function __construct($targetDir, $name, $rootDir) {
    $this->targetDir = $targetDir;
    $this->name = $name;
    $this->rootDir = $rootDir;
    $this->targetShortPath =
        substr($this->targetDir, strlen($this->rootDir) + 1);
    $this->rootNamespace = str_replace("/", "\\", $this->targetShortPath);
  }

  private function createProject() {
    echo "Dir: {$this->targetDir}\n";
    echo "Target: {$this->name}\n";

    $parentDir = dirname($this->targetDir);
    $cwd = getcwd();

    chdir($parentDir);

    // Create the scaffolding project.
    if (!file_exists("{$this->targetDir}/artisan")) {
      self::runCommand([
          'composer',
          'create-project',
          '--prefer-dist',
          'laravel/laravel',
          $this->name]);
    } else {
      echo "Skipped composer step: artisan already present.\n";
    }

    // Remove the vendor dir from source tree.
    $vendorDir = $this->targetDir . "/vendor";

    if (file_exists($vendorDir)) {
      $this->rmdir($vendorDir);
    } else {
      echo "Skipped remove vendor step: already missing.\n";
    }

    $removed = $this->removeFiles(
        ['composer.*', 'phpunit.xml', 'readme.md', '.evn.example']);
    if ($removed == 0) {
      echo "Skipped file removal: already removed\n";
    }

    if ($this->renameDirectories() == 0) {
      echo "Skipped directory renaming: already at lowercase\n";
    }

    if ($this->updateSourceFiles() == 0) {
      echo "Skipped source file updates: already up to date\n";
    }

    return;

    if ($this->createBuildFiles() == 0) {
      echo "Skipped create BUILD files: already up to date\n";
    }

  }


  private function updateBootstrapAutoload() {
    // "{$this->targetDir}/bootstrap/autoload.php":
  }

  private function createBuildFiles() {
    $srcs = $this->getSourceFiles();
    $dirs = array();
    $index = 0;
    foreach ($srcs as $src) {
      $dir = dirname($src);
      if (!array_key_exists($dir, $dirs)) {
        $dirs[$dir] = 0;
      }
      $dirs[$dir] = $index++;
    }
    $dirs = array_values(array_flip($dirs));

    $numBuildFiles = 0;
    foreach ($dirs as $dir) {
      $eol = "\n";
      $targetName = basename($dir);
      if ($targetName == "")
        $targetName = $this->$name;


      $deps = str_replace("\\", "", json_encode(["@laravel//:laravel"]));
      $content =
          'load("//tools/build:php.bzl", "php_library")' . $eol .
          '' . $eol .
          'php_library(' . $eol .
          '  name="' . $targetName . '",' . $eol .
          '  srcs=glob(["*.php"], exclude=["*Test.php"]),' . $eol .
          '  deps=' . $deps . ',' . $eol .
          '  visibility=["//' . $this->targetShortPath . ':__subpackages__"],' .
              $eol .
          ')' . $eol
      ;

      $buildFile = "{$dir}/BUILD";
      if (file_exists($buildFile) &&
          file_get_contents($buildFile) == $content) {
        continue;
      }

      file_put_contents($buildFile, $content);
      $numBuildFiles++;
    }

    return $numBuildFiles;
  }

  private function updateSourceFiles() {
    // Mapping of old namespaces to new ones.
    $namespaceMap = [];
    $srcs = $this->getSourceFiles();
    $modifyCount = 0;
    foreach ($srcs as $src) {
      $content = file_get_contents($src);
      $namespace =
          str_replace(
              '/',
              '\\',
              dirname(substr($src, strlen($this->rootDir) + 1)));

      $newContent = $content;

      // Namespace fix.
      $matches = [];
      $namespacePattern = '/^namespace (.*?);\s*$/m';
      if (preg_match_all($namespacePattern, $newContent, $matches)) {
        foreach ($matches[1] as $match) {
          $namespaceMap["\\{$match}"] = "\\{$namespace}";
          $newContent = preg_replace(
            $namespacePattern, "namespace {$namespace};", $newContent);
        }
      }

      // Braces position to K&R style.
      $newContent =
          preg_replace('/(.*?)\n\s*{/s', '\1 {', $newContent);

      // Double spaces instead of quadruple.
      // Assume if there were changes above, we can do this now (once only).
      if ($content != $newContent) {
        $newContent = str_replace(str_repeat(" ", 4),
                                  str_repeat(" ", 2),
                                  $newContent);
      }

      $modifyCount += $this->safeReplaceSource($src, $newContent);
    }

    print_r($namespaceMap);

    // Update namespace references.
    foreach ($srcs as $src) {
      $content = file_get_contents($src);
      $newContent = strtr($content, $namespaceMap);
      $modifyCount += $this->safeReplaceSource($src, $newContent);
    }

    return $modifyCount;
  }

  private function renameDirectories() {
    $dirs = $this->getDirectories();
    $depthMap = [];
    foreach ($dirs as $dir) {
      $key = substr_count($dir, '/');
      $depthMap[$dir] = $key;
    }

    arsort($depthMap);
    $sortedDirs = array_keys($depthMap);
    $renameCount = 0;

    foreach ($sortedDirs as $dir) {
      $shortPath = substr($dir, strlen($this->targetDir) + 1);
      $newShortPath =
          dirname($shortPath) . "/" . strtolower(basename($shortPath));
      // The dirname of a "current_level_dir" is ".", so check that too.
      if ($shortPath == $newShortPath || "./$shortPath" == $newShortPath) {
        continue;
      }

      echo "rename($shortPath, $newShortPath)\n";
      $newName = "{$this->targetDir}/{$newShortPath}";
      rename($dir, $newName);
      $renameCount++;
    }

    return $renameCount;
  }

  private function safeReplaceSource($src, $newContent) {
    $oldContent = file_get_contents($src);
    if ($oldContent == $newContent)
      return 0;

    // Check syntax before saving.
    $this->checkSyntax($newContent);
    file_put_contents($src, $newContent);

    return 1;
  }

  /** Checks PHP source code syntax. Throws with error message on failure. */
  private function checkSyntax($sourceCode) {
    $this->withTempFileOfContents($sourceCode, function($tempFile) {
      self::runCommand(["php", "-l", $tempFile, "> /dev/null"]);
    });
  }

  private function withTempFileOfContents($contents = "", $callback) {
    $filename = tempnam("/tmp", "temp_php");
    try {
      self::$tempFiles[] = $filename;
      file_put_contents($filename, $contents);
      call_user_func($callback, $filename);
    } finally {
      unlink($filename);
    }
  }

  private function removeFiles($patterns) {
    $removed = 0;
    foreach ($patterns as $pattern) {
      foreach (glob("{$this->targetDir}/{$pattern}") as $file) {
        $this->rm($file);
        $removed++;
      }
    }

    return $removed;
  }

  private function getSourceFiles() {
    return self::rglob("{$this->targetDir}/*.php");
  }

  private function getDirectories() {
    return self::rglob("{$this->targetDir}/*", GLOB_ONLYDIR);
  }

  private function rm($file) {
    $this->checkTargetDir($file);
    if (!unlink($file)) {
      throw new Exception("Could not remove file: $file");
    }
  }

  private function rmdir($dir) {
    $this->checkTargetDir($dir);
    self::runCommand(['rm', '-rf', $dir]);
  }

  private function checkTargetDir($dir) {
    $dir = realpath($dir);
    if (substr($dir, 0, strlen($this->targetDir) + 1) !=
        $this->targetDir . "/") {
      throw new Exception(
          "Can't operate on files below: {$this->targetDir}. Got: {$dir}");
    }
  }

  public static function cleanUp() {
    foreach (self::$tempFiles as $filename) {
      if (file_exists($filename)) {
        unlink($filename);
      }
    }
  }

  private static function runCommand($cmd) {
    $cmdStr = implode(" ", $cmd);
    system($cmdStr, $retval);
    if ($retval != 0) {
      throw new Exception("Failed command: {$cmdStr}");
    }
  }

  private static function rglob($pattern, $flags = 0) {
    $files = glob($pattern, $flags);
    foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
      $files =
          array_merge($files, self::rglob($dir.'/'.basename($pattern), $flags));
    }
    return $files;
  }
}

Laravel::main(array_slice($argv, 1));