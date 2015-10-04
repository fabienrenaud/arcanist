<?php

final class ArcanistBundleTestCase extends PhutilTestCase {

  private function loadResource($name) {
    return Filesystem::readFile($this->getResourcePath($name));
  }

  private function getResourcePath($name) {
    return dirname(__FILE__).'/bundle/'.$name;
  }

  private function loadDiff($old, $new) {
    list($err, $stdout) = exec_manual(
      'diff --unified=65535 --label %s --label %s -- %s %s',
      'file 9999-99-99',
      'file 9999-99-99',
      $this->getResourcePath($old),
      $this->getResourcePath($new));
    $this->assertEqual(
      1,
      $err,
      pht(
        "Expect `%s` to find changes between '%s' and '%s'.",
        'diff',
        $old,
        $new));
    return $stdout;
  }

  private function loadOneChangeBundle($old, $new) {
    $diff = $this->loadDiff($old, $new);
    return ArcanistBundle::newFromDiff($diff);
  }

  /**
   * Unarchive a saved git repository and apply each commit as though via
   * "arc patch", verifying that the resulting tree hash is identical to the
   * tree hash produced by the real commit.
   */
  public function testGitRepository() {
    if (phutil_is_windows()) {
      $this->assertSkipped(pht('This test is not supported under Windows.'));
    }

    $archive = dirname(__FILE__).'/bundle.git.tgz';
    $fixture = PhutilDirectoryFixture::newFromArchive($archive);

    $old_dir = getcwd();
    chdir($fixture->getPath());

    $caught = null;
    try {
      $this->runGitRepositoryTests($fixture);
    } catch (Exception $ex) {
      $caught = $ex;
    }

    chdir($old_dir);

    if ($caught) {
      throw $ex;
    }
  }

  private function runGitRepositoryTests(PhutilDirectoryFixture $fixture) {
    $patches = dirname(__FILE__).'/patches/';

    list($commits) = execx(
      'git log --format=%s',
      '%H %T %s');
    $commits = explode("\n", trim($commits));

    // The very first commit doesn't have a meaningful parent, so don't examine
    // it.
    array_pop($commits);

    foreach ($commits as $commit) {
      list($commit_hash, $tree_hash, $subject) = explode(' ', $commit, 3);
      execx('git reset --hard %s --', $commit_hash);

      $fixture_path = $fixture->getPath();
      $working_copy = ArcanistWorkingCopyIdentity::newFromPath($fixture_path);

      $configuration_manager = new ArcanistConfigurationManager();
      $configuration_manager->setWorkingCopyIdentity($working_copy);
      $repository_api = ArcanistRepositoryAPI::newAPIFromConfigurationManager(
        $configuration_manager);

      $repository_api->setBaseCommitArgumentRules('arc:this');
      $diff = $repository_api->getFullGitDiff(
        $repository_api->getBaseCommit(),
        $repository_api->getHeadCommit());

      $parser = new ArcanistDiffParser();
      $parser->setRepositoryAPI($repository_api);
      $changes = $parser->parseDiff($diff);

      $this->makeChangeAssertions($commit_hash, $changes);

      $bundle = ArcanistBundle::newFromChanges($changes);

      execx('git reset --hard %s^ --', $commit_hash);

      $patch = $bundle->toGitPatch();

      $expect_path = $patches.'/'.$commit_hash.'.gitpatch';
      $expect = null;
      if (Filesystem::pathExists($expect_path)) {
        $expect = Filesystem::readFile($expect_path);
      }

      if ($patch === $expect) {
        $this->assertEqual($expect, $patch);
      } else {
        Filesystem::writeFile($expect_path.'.real', $patch);
        throw new Exception(
          pht(
            "Expected patch and actual patch for %s differ. ".
            "Wrote actual patch to '%s.real'.",
            $commit_hash,
            $expect_path));
      }

      try {
        id(new ExecFuture('git apply --index --reject'))
          ->write($patch)
          ->resolvex();
      } catch (CommandException $ex) {
        $temp = new TempFile(substr($commit_hash, 0, 8).'.patch');
        $temp->setPreserveFile(true);
        Filesystem::writeFile($temp, $patch);

        PhutilConsole::getConsole()->writeErr(
          "%s\n",
          pht("Wrote failing patch to '%s'.", $temp));
        throw $ex;
      }

      $author = 'unit-test <unit-test@phabricator.com>';

      execx('git commit --author %s -m %s', $author, $subject);
      list($result_hash) = execx('git log -n1 --format=%s', '%T');
      $result_hash = trim($result_hash);

      $this->assertEqual(
        $tree_hash,
        $result_hash,
        pht('Commit %s: %s', $commit_hash, $subject));
    }
  }

  private function makeChangeAssertions($commit, array $raw_changes) {
    $changes = array();

    // Verify that there are no duplicate changes, and rekey the changes on
    // affected path because we don't care about the order in which the
    // changes appear.
    foreach ($raw_changes as $change) {
      $this->assertTrue(
        empty($changes[$change->getCurrentPath()]),
        'Unique Path: '.$change->getCurrentPath());
      $changes[$change->getCurrentPath()] = $change;
    }

    switch ($commit) {
      case '1830a13adf764b55743f7edc6066451898d8ffa4':
        // "Mark koan2 as +x and edit it."

        $this->assertEqual(1, count($changes));

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $c->getType());

        $this->assertEqual(
          '100644',
          idx($c->getOldProperties(), 'unix:filemode'));

        $this->assertEqual(
          '100755',
          idx($c->getNewProperties(), 'unix:filemode'));
        break;
      case '8ecc728bcc9b482a9a91527ea471b04fc1a025cf':
        // "Move 'text' to 'executable' and mark it +x."

        $this->assertEqual(2, count($changes));

        $c = $changes['executable'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());

        $this->assertEqual(
          '100644',
          idx($c->getOldProperties(), 'unix:filemode'));

        $this->assertEqual(
          '100755',
          idx($c->getNewProperties(), 'unix:filemode'));
        break;
      case '39c8e7dd3914edff087a6214f0cd996ad08e5b3d':
        // "Mark koan as +x."
        // Primarily a test against a recusive synthetic hunk construction bug.
        $this->assertEqual(1, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $c->getType());

        $this->assertEqual(
          '100644',
          idx($c->getOldProperties(), 'unix:filemode'));

        $this->assertEqual(
          '100755',
          idx($c->getNewProperties(), 'unix:filemode'));
        break;
      case 'c573c25d1a767d270fed504cd993e78aba936338':
        // "Copy a koan over text, editing the original koan."
        // Git doesn't really do anything meaningful with this.

        $this->assertEqual(2, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $c->getType());

        $c = $changes['text'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $c->getType());

        break;
      case 'd26628e588cf7d16368845b121c6ac6c781e81d0':
        // "Copy a koan, modifying both the source and destination."

        $this->assertEqual(2, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_AWAY,
          $c->getType());

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $c->getType());

        break;
      case 'b0c9663ecda5f666f62dad245a3a7549aac5e636':
        // "Remove a koan copy."

        $this->assertEqual(1, count($changes));

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $c->getType());

        break;
      case 'b6ecdb3b4801f3028d88ba49940a558360847dbf':
        // "Copy a koan and edit the destination."
        // Git does not detect this as a copy without --find-copies-harder.

        $this->assertEqual(1, count($changes));

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $c->getType());

        break;
      case '30d23787e1ecd254c884afbe37afa612f61e3904':
        // "Move and edit a koan."

        $this->assertEqual(2, count($changes));

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_AWAY,
          $c->getType());

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());

        break;
      case 'c0ba9bfe3695f95c3f558bc5797eeba421d32483':
        // "Remove two koans."

        $this->assertEqual(2, count($changes));

        $c = $changes['koan3'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $c->getType());

        $c = $changes['koan4'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $c->getType());

        break;
      case '2658fd01d5355abe5d4c7ead3a0e7b4b3449fe77':
        // "Multicopy a koan."

        $this->assertEqual(3, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MULTICOPY,
          $c->getType());

        $c = $changes['koan3'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $c->getType());

        $c = $changes['koan4'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());

        break;
      case '1c5fe4e2243bb19d6b3bf15896177b13768e6eb6':
        // "Copy a koan."
        // Git does not detect this as a copy without --find-copies-harder.

        $this->assertEqual(1, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $c->getType());

        break;
      case '6d9eb65a2c2b56dee64d72f59554c1cca748dd34':
        // "Move a koan."

        $this->assertEqual(2, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_AWAY,
          $c->getType());

        $c = $changes['koan2'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());

        break;
      case '141452e2a775ee86409e8779dd2eda767b4fe8ab':
        // "Add a koan."

        $this->assertEqual(1, count($changes));

        $c = $changes['koan'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $c->getType());

        break;
      case '5dec8bf28557f078d1987c4e8cfb53d08310f522':
        // "Copy an image, and replace the original."
        // `image_2.png` is copied to `image.png` and then replaced.

        $this->assertEqual(2, count($changes));

        $c = $changes['image.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        $c = $changes['image_2.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_AWAY,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getOriginalFileData()));
        $this->assertEqual(
          'c9ec1b952480da09b393ba672d9b13da',
          md5($c->getCurrentFileData()));

        break;
      case 'fb28468d25a5fdd063aca4ca559454c998a0af51':
        // "Multicopy image."
        // `image.png` is copied to `image_2.png` and `image_3.png` and then
        // deleted. Git detects this as a move and an add.

        $this->assertEqual(3, count($changes));

        $c = $changes['image.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MULTICOPY,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getOriginalFileData()));
        $this->assertEqual(
          null,
          $c->getCurrentFileData());

        $c = $changes['image_2.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_COPY_HERE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        $c = $changes['image_3.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        break;
      case 'df340e88d8aba12e8f2b8827f01f0cd9f35eb758':
        // "Remove binary image."
        // `image_2.png` is deleted.

        $this->assertEqual(1, count($changes));

        $c = $changes['image_2.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_DELETE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getOriginalFileData()));
        $this->assertEqual(
          null,
          $c->getCurrentFileData());

        break;
      case '3f5c6d735e64c25a04f83be48ef184b25b5282f0':
        // "Copy binary image."
        // `image_2.png` is copied to `image.png`. Git does not detect this as
        // a copy without --find-copies-harder.

        $this->assertEqual(1, count($changes));

        $c = $changes['image.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        break;
      case 'b454edb3bb29890ee5b3af5ef66ce6a24d15d882':
        // "Move binary image."
        // `image.png` is moved to `image_2.png`.

        $this->assertEqual(2, count($changes));

        $c = $changes['image.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_AWAY,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getOriginalFileData()));
        $this->assertEqual(
          null,
          $c->getCurrentFileData());

        $c = $changes['image_2.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_MOVE_HERE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        break;
      case '5de5f3dfda1b7db2eb054e57699f05aaf1f4483e':
        // "Add a binary image."
        // `image.png` is added.

        $c = $changes['image.png'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_ADD,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_BINARY,
          $c->getFileType());
        $this->assertEqual(
          null,
          $c->getOriginalFileData());
        $this->assertEqual(
          '8645053452b2cc2f955ef3944ac0831a',
          md5($c->getCurrentFileData()));

        break;
      case '176a4c2c3fd88b2d598ce41a55d9c3958be9fd2d':
        // "Convert \r\n newlines to \n newlines."
      case 'a73b28e139296d23ade768f2346038318b331f94':
        // "Add text with \r\n newlines."
      case '337ccec314075a2bdb4a912ef467d35d04a713e4':
        // "Convert \n newlines to \r\n newlines.";
      case '6d5e64a4a7a6a036c53b1d087184cb2c70099f2c':
        // "Remove tabs."
      case '49395994a1a8a06287e40a3b318be4349e8e0288':
        // "Add tabs."
      case 'a5a53c424f3c2a7e85f6aee35e834c8ec5b3dbe3':
        // "Add trailing newline."
      case 'd53dc614090c6c7d6d023e170877d7f611f18f5a':
        // "Remove trailing newline."
      case 'f19fb9fa1385c01b53bdb6d8842dd154e47151ec':
        // "Edit a text file."

        $this->assertEqual(1, count($changes));

        $c = $changes['text'];
        $this->assertEqual(
          ArcanistDiffChangeType::TYPE_CHANGE,
          $c->getType());
        $this->assertEqual(
          ArcanistDiffChangeType::FILE_TEXT,
          $c->getFileType());
        break;
      case '228d7be4840313ed805c25c15bba0f7b188af3e6':
        // "Add a text file."
        // This commit is never reached because we skip the 0th commit junk.
        $this->assertTrue(true, pht('This is never reached.'));
        break;
      default:
        throw new Exception(
          pht('Commit %s has no change assertions!', $commit));
    }
  }

  public function testTrailingContext() {
    // Diffs need to generate without extra trailing context, or 'patch' will
    // choke on them.
    $this->assertEqual(
      $this->loadResource('trailing-context.diff'),
      $this->loadOneChangeBundle(
        'trailing-context.old',
        'trailing-context.new')->toUnifiedDiff());
  }

  public function testDisjointHunks() {
    // Diffs need to generate without overlapping hunks.
    $this->assertEqual(
      $this->loadResource('disjoint-hunks.diff'),
      $this->loadOneChangeBundle(
        'disjoint-hunks.old',
        'disjoint-hunks.new')->toUnifiedDiff());
  }

  public function testNonlocalTrailingNewline() {
    // Diffs without changes near the end of the file should not generate a
    // bogus, change-free hunk if the file has no trailing newline.
    $this->assertEqual(
      $this->loadResource('trailing-newline.diff'),
      $this->loadOneChangeBundle(
        'trailing-newline.old',
        'trailing-newline.new')->toUnifiedDiff());
  }

  public function testEncodeBase85() {

    $data = '';
    for ($ii = 0; $ii <= 255; $ii++) {
      $data .= chr($ii);
    }
    for ($ii = 255; $ii >= 0; $ii--) {
      $data .= chr($ii);
    }

    $expect = Filesystem::readFile(dirname(__FILE__).'/base85/expect1.txt');
    $expect = trim($expect);

    $this->assertEqual(
      $expect,
      ArcanistBundle::encodeBase85($data));

    // This is just a large block of random binary data, it has no special
    // significance.

    $data =
      "\x56\x4c\xb3\x63\xe5\x4a\x9f\x03\xa3\x4c\xdd\x5d\x85\x86\x10".
      "\x30\x3f\xc1\x28\x51\xd8\xb2\x1a\xc3\x79\x15\x85\x31\x66\xf9".
      "\x8e\xe1\x20\x8f\x12\xa1\x94\x0e\xbf\xb6\x9c\xb5\xc0\x15\x43".
      "\x3d\xad\xed\x00\x3c\x16\xfa\x76\x2f\xed\x99\x3a\x78\x3e\xd1".
      "\x91\xf8\xb0\xca\xb9\x29\xfe\xd4\x0f\x16\x70\x19\xad\xd9\x42".
      "\x15\xb4\x8f\xd6\x8f\x80\x62\xe9\x48\x77\x9f\x38\x6d\x3f\xd6".
      "\x0e\x40\x68\x68\x93\xae\x75\x6d\x7f\x75\x9c\x80\x69\x94\x22".
      "\x87\xb6\xc0\x62\x6b\xab\x49\xb8\x91\xe9\x96\xbf\x04\xc2\x50".
      "\x30\xae\xea\xc1\x70\x8e\x91\xd0\xb6\xec\x56\x14\x78\xd5\x8a".
      "\x8c\x52\xd1\x3c\xde\x65\x21\xec\x93\xab\xcf\x7e\xf5\xfd\x6d".
      "\x2d\x69\xb9\x2e\xa3\x42\x7b\x4d\xa5\xfb\x28\x6d\x74\xa3\x7b".
      "\x3a\xc5\x34\x7c\x63\xa9\xf9\x8e\x34\x14\x42\xb0\xf1\x0e\xe2".
      "\xd0\xd2\x04\x81\xff\x62\xd5\xd9\x46\x3b\x36\x88\x8a\x93\x55".
      "\x02\x2c\xff\x9f\x48\xd6\x7a\xcb\xbf\x6a\x33\xaa\x6b\x08\x4c".
      "\x96\x98\x89\x53\x56\xb4\xb3\x9b\x06\xb1\xa0\x13\x69\xfa\x6a".
      "\xa8\x0d\x6a\xda\xb2\x6f\x62\x0b\xa8\xf6\x59\x29\x46\x7d\x04".
      "\x44\xeb\x90\x6f\xd7\xc7\xb6\xca\xc5\xeb\xde\x10\x9b\xbd\xf2".
      "\x66\x8e\xd0\x0b\xda\x8c\xeb\x90\x73\x73\x33\xe7\x6f\x26\x57".
      "\x4e\xfc\x95\xe0\xfc\x62\x93\xa7\x28\xe6\x0c\x46\x73\xdd\x01".
      "\xce\x43\x9b\x4e\x16\x74\x5b\x36\x92\x5a\x66\x4c\xe3\x9e\x90".
      "\x2d\x9a\x1a\x3d\x69\x39\x67\x04\xd6\xf8\x5f\x45\xee\xbb\xd4".
      "\x63\xcf\x8c\x9b\x31\x69\x98\x1a\x98\x57\x4b\xa9\x49\xf6\x1b".
      "\x76\x28\xd7\xe3\x8f\x63\x95\x5b\x06\xe2\xa8\x66\x60\xf9\x49".
      "\x4e\x40\x53\x32\x9b\x74\x36\xc0\x56\xf4\x33\xec\x83\xd2\x2c".
      "\x69\x60\x55\x11\x3b\x4f\xd6\x0a\xf6\x04\x38\x75\xb6\xc2\x82".
      "\x4d\xfa\x83\x56\xba\x35\x42\xc3\xcb\xdc\x28\xf4\x69\x48\xa9".
      "\xe0\x51\x41\x79\x66\xfe\x61\xd1\xf2\x9f\x7b\xde\xc4\x3e\x8f".
      "\x8f\xb6\x9c\x0a\x74\xf8\x71\x03\x37\x37\x30\x8d\x2a\x6a\xc9".
      "\x51\xa1\xe2\x34\xe5\x42\xdb\x4f\x61\x4e\x16\xfc\x23\x72\x12".
      "\x46\x53\x12\x82\x3e\x44\x63\x23\x82\xaa\xab\x7e\x8d\x70\x66".
      "\xf1\x94\x86\x02\xc5\x3e\x9c\x79\x17\x1e\x9f\x13\x89\x3d\x25".
      "\x45\xc9\x3b\x1e\xa0\x1a\x03\x20\x1c\x81\x6b\xfc\xb5\xc9\xe2".
      "\xda\xb1\x87\x34\xa0\xb2\x72\x36\x68\x12\x05\x53\x7c\x68\x6b".
      "\x1e\x2a\x56\x2a\x7e\x7f\xd0\x9c\x13\xa9\xb2\x4c\xe6\x8a\x65".
      "\xd7\x67\xad\xf3\xf3\x2b\x9c\xe8\x10\x07\x8a\xe2\x20\x67\xe4".
      "\x51\x47\xc1\x22\x91\x05\x22\x39\x1a\xef\x54\xd2\x8a\x88\x55".
      "\x3f\x83\xba\x73\xd4\x95\xc7\xb8\xa2\xfd\x4d\x4e\x5d\xff\xdd".
      "\xaf\x1a\xc2\x7e\xb5\xfa\x86\x5f\x93\x38\x5d\xca\x9a\x5a\x7e".
      "\xb7\x47\xd5\x5c\x6b\xf3\x32\x03\x11\x44\xe9\x49\x12\x40\x82".
      "\x67\x7d\x2a\x5a\x61\x81\xbd\x24\xaa\xd7\x7c\xc9\xcf\xaf\xb0".
      "\x3e\xb0\x43\xcd\xce\x21\xe4\x1b\x5a\xd6\x40\xf5\x0e\x23\xef".
      "\x70\xf4\xc6\xd2\xd7\x36\xd7\x20\xda\x8d\x39\x46\xea\xfc\x78".
      "\x55\xa2\x02\xd6\x77\x21\xc8\x97\x1e\xdf\x45\xde\x93\xa7\x74".
      "\xd8\x59\x10\x24\x8a\xe8\xcd\xe9\x00\xb5\x4e\xe6\x49\xb0\xde".
      "\x14\x1a\x5d\xdd\x38\x47\xb0\xc7\x1e\xec\x7c\x76\xc9\x21\x3c".
      "\x3a\x85\x4f\x71\x97\xed\x4a\x94\x2c\x51\x48\x9c\x43\x90\x70".
      "\xe9\x0e\x84\x55\xd2\xa4\x48\xfa\xfd\x54\x12\x11\xb9\x32\xfc".
      "\x1d\x66\xe7\x42\xe3\x5e\x65\xf4\x3d\xea\x1a\x53\xe3\x7b\x4b".
      "\xee\xdb\x74\xce\x30\xd3\x04\xcb\xda\xa4\xdd\xad\x98\x3a\x76".
      "\xe8\xba\x1b\x03\x53\xed\x46\x5d\xef\xd4\x34\xc2\x8d\xef\xae".
      "\x51\x35\x0f\x4d\x40\xaa\x3a\xdb\x50\x1a\xbe\x5f\x8b\xb8\x24".
      "\x40\x19\x8f\x8a\x6b\x44\x4f\x9b\xe0\xf4\x9c\x4b\xc4\x23\x37".
      "\xf0\xb3\xe1\x58\x9d\x0e\xd9\xa9\xf7\x3e\x86\x43\x9b\x5b\x90".
      "\x3c\xc0\x20\xa0\xc5\x86\x4f\xc6\xcb\xb5\xcb\xd4\x88\xc6\x72".
      "\x57\xa7\x57\x2c\x34\x26\x91\x44\x15\xa8\xf4\x88\xca\x74\x56".
      "\x9e\x12\x6c\xdf\x52\xef\xc0\xb4\x5c\x16\xe8\xaa\xf7\xb6\xf3".
      "\x7c\xda\xcd\x42\xf9\x1c\x40\x88\x44\x68\x4f\x1b\x5a\x7b\x8f".
      "\xc3\x47\x48\xd3\xf3\xe5\xf5\x66\x35\x48\xbe\x64\xdf\xfe\x35".
      "\xf1\xc3\xe4\xa8\xfc\x86\xfb\x69\x20\xc9\xf4\x16\x96\xc1\x7a".
      "\x51\x14\x77\xa4\x6e\x13\xe8\x59\x35\x24\xf1\xe5\xfe\xe9\x98".
      "\x0d\xd1\xe8\xce\x9c\x7f\xf8\x3b\x79\x39\x3a\x1d\xa3\x77\xef".
      "\x4f\x4b\x59\x73\x03\xb3\xfe\xae\x70\x2a\x3a\xf0\x79\x9d\x7e".
      "\x9b\xaa\xb1\x18\xf9\x43\x69\xf3\x55\x46\xad\x38\xa2\xf1\xcb".
      "\xce\x37\xa9\x88\x20\x38\xea\x19\x29\x95\x8c\x75\x06\x9d\x1d".
      "\x9e\xf2\xb7\x64\x98\x21\x36\x90\x92\xf8\xb8\x89\x1e\x5c\x5d".
      "\x09\x3b\x52\xc5\x6a\x87\x7e\x46\xca\x8c\xdf\xe7\xca\xa9\x7b".
      "\x11\x63\x0f\x9e\x42\x9a\x3e\xe0\x8b\x80\x9e\x91\x76\x88\x9a".
      "\xa1\xe2\x96\xae\xfb\x18\x39\xdc\x92\x99\x34\xfd\x98\x20\xa8".
      "\x89\x61\x2c\x26\xe0\xb8\x83\xa7\xe7\x50\x42\x8f\xfc\x36\x66".
      "\x6b\x25\xc5\x6d\xb4\x31\xe1\x4d\x0f\x2e\xf8\x44\xe2\xb6\x6a".
      "\x6d\xfe\x83\x9e\x2c\x07\x2f\x15\x41\xf3\xe7\xa6\x18\x2b\x84".
      "\x7e\xeb\x43\xcc\xbb\xdb\xa9\x54\x5c\xbc\x59\x6a\xdc\x26\x2a".
      "\xf4\x59\xa7\x75\xa4\xac\xed\x73\x8f\x16\x43\x0d\x97\x10\x2c".
      "\x70\xef\x9e\xb2\xc9\xdf\xe6\xa7\x9b\x08\x79\xa3\xf7\x99\xf5".
      "\x59\xe4\xd5\x89\x10\xe5\xc9\xf7\xe7\x29\x72\x06\xc6\x54\xc3".
      "\xcd\xd0\xff\x69\xf8\xdf\x19\xf2\x66\x1c\x69\x40\xbc\x97\xf1".
      "\x49\x5e\x78\x62\x52\x46\x7f\xcf\x44\x50\x8b\x5f\xe7\xa8\xeb".
      "\xd5\x84\x24\x81\xc0\x2c\x65\xf7\x95\xbd\xf2\x8e\x43\xfb\x6a".
      "\x49\x3c\x6a\xe5\x2a\x39\xf0\xfa\x89\x59\x5f\x39\x75\xb4\x6f".
      "\x04\xf1\xe0\x2c\xcd\x77\x34\xec\x6b\x45\x16\xe3\x18\x24\x05".
      "\xb9\x68\xc1\x4e\x71\x4b\xff\x88\x18\xea\x0d\x56\x49\x55\xdf".
      "\xe5\xb0\x59\xdb\x74\x9e\x0b\x38\x03\x9f\x10\x6f\xd9\x34\x07".
      "\x44\x29\x08\xb1\xd4\x77\xc6\x84\x0d\xbb\xb5\xd5\x09\x05\x19".
      "\x01\x62\x29\x45\x52\x1d\xc6\x4f\x25\x78\x7e\xbc\xae\x07\xb3".
      "\xd4\xe0\x19\x91\x03\xd6\x8d\x2f\x00\xc9\xb2\x66\x3b\x4e\x3d".
      "\x75\xf7\x23\x9a\x3e\xa4\xd5\x7f\x75\x47\xd0\xbc\xc3\xc8\x2a".
      "\xdc\x85\x09\x6c\x0c\x90\x38\xd8\xef\xcf\xf4\x7a\x1b\xc7\x76".
      "\xe0\xdb\x81\xa8\x1b\x2b\x8d\xd4\x36\x90\x76\xde\x8a\x90\xc8".
      "\x5b\x05\x00\xeb\xb3\x20\xce\x6e\x5c\xb9\x35\x3d\x95\x3a\x79".
      "\x4a\x60\xeb\x23\x11\xfb\x90\x2d\xf6\xb7\x05\x4a\x43\x41\x79".
      "\x51\xaa\xe6\x90\x0a\x71\x87\x80\xbe\xb0\x89\x0f\xd3\x84\x19".
      "\xce\x6c\xf9\xbb\x1b\x15\x4d\x0f\x33\x65\xf7\x9e\x3a\xd9\x8c".
      "\x02\x43\xcf\xdf\xb2\x60\xc1\x4c\xe9\xa5\x3c\xaf\xfa\x41\x2d".
      "\xb9\x1f\x45\x32\xcb\x39\x2f\x94\xae\x44\x6d\x69\xc1\xc9\x57".
      "\x8c\xe5\xf4\xa4\x3a\xb6\x70\x61\xf9\xbb\x41\xdc\x78\xf0\xf7".
      "\xbf\xa8\x8e\xe3\x77\x51\xce\x25\x2f\xdf\x27\x6b\x07\x30\x9f".
      "\xce\xdb\x59\x58\xaa\xb2\x2e\xdc\x90\x92\x82\x55\xfe\x25\x36".
      "\x49\x7f\x6d\x2d\x39\x51\xef\x3d\xc8\xa3\x87\x0b\xe7\xf2\xac".
      "\x90\xa0\x1d\xd8\xc7\xea\x93\x53\x3b\x21\x84\x2e\x52\x6c\xfb".
      "\x4f\x31\xda\xd1\xea\x45\x3e\xdc\xeb\x52\x81\x8c\x2b\xf4\x2a".
      "\xbc\x01\xc4\xe7\x68\x36\x9c\xd5\x2d\xc1\x61\xcb\x9a\x5f\x18".
      "\x00\x6a\xc8\x9a\x4e\xfd\x31\x5b\xce\x90\x4e\x45\xff\x7f\xea".
      "\xb2\x26\xad\xc1\x3a\x21\xa9\xe8\x7c\x14\xae\x81\x1e\xbe\xa3".
      "\x6d\xda\x92\x1b\xeb\xf2\x69\x76\x3e\xf1\x2b\xf7\x1a\x45\xd5".
      "\xb3\x81\xb1\xbe\x80\x7f\x24\xba\x0e\xd5\x68\x34\x3f\x1a\x29".
      "\x15\x0e\xc2\x26\x62\x0c\xaa\xa9\x20\x4c\x61\x65\x49\x07\xbe".
      "\x69\xf4\xc9\xec\x2f\x1c\xfa\x59\x2e\x72\xc0\x17\xc5\x4c\xfa".
      "\xba\x2f\x64\xab\xa9\xb4\xcb\xdc\xcb\x25\x5f\xcf\x0c\x87\xcc".
      "\xf0\x36\x2b\xce\x81\x5a\x22\x85\xa0\x50\x50\x97\x8e\xda\x36".
      "\x80\x74\xb5\x1e\x02\x3f\xd7\xc8\x29\x11\xeb\x1d\x3d\x74\x9f".
      "\x26\x1a\xa4\x3d\xf9\x0e\xf0\x2d\x5c\xa9\x43\xbf\x51\x6c\x8d".
      "\xe6\x78\xe0\x67\x57\xf0\xc8\x0e\x97\x9c\x57\x23\x30\xac\x63".
      "\xdf\x46\x98\xa4\xaf\x4e\xa7\xe5\xac\x31\xbd\xeb\x6a\xa0\xb0".
      "\xe4\x94\x7e\x51\xf6\x89\x81\x3e\xab\x4f\x64\xb7\xc5\x51\x71".
      "\xcd\x74\x02\xa9\x02\x99\x5c\xab\x0e\x14\x47\x3b\x04\xc1\x9b".
      "\x59\x1a\x93\x92\x4c\x71\x20\x5f\x6e\xd3\xf3\xa7\x47\x1b\x39".
      "\x3e\x73\x69\xe2\xec\xcb\x52\xb3\x5c\x7a\x95\x25\x3f\x16\x98".
      "\x60\xa8\xa2\x5d\xc4\x5a\x67\xe4\x11\x06\x06\xf9\x7a\xb4\x14".
      "\xe0\xbc\x7b\x13\x1d\x0f\xf2\xca\x0b\xd4\xaa\x71\x35\x3e\xd6".
      "\x2e\x2e\x5d\x7b\x15\xc9\x23\x1a\xa9\x24\x31\x48\xd4\xcf\x4a".
      "\xf4\x32\x17\x9b\x1d\x4b\xfe\x49\x69\xd6\xc0\x8f\xb9\xdb\x72".
      "\x52\x2c\xe8\xf3\xc4\xfc\x46\xf5\xb8\x1b\x05\x06\xcf\xcc\x23".
      "\x34\xbf\x25\x6a\xea\x3c\xc7\x64\xd4\xd5\xb3\x67\xed\x24\x27".
      "\xd3\x67\xc1\xbd\x9f\x7b\x7d\x19\x04\x5c\xd1\x96\x7e\xa5\xc7".
      "\xbb\xb2\x84\x68\x98\x38\x11\x90\xfb\x62\x15\xfd\xe6\xb7\x24".
      "\x77\xb2\x78\xc7\x73\x91\xc9\x60\x1d\x91\x6d\x04\x2b\x41\xe9".
      "\xc9\xfa\xe4\x98\x54\x83\x9a\x6e\x76\x8c\x21\xf9\x91\x38\x1f".
      "\xdc\xfe\x13\x09\x30\xd7\x53\x63\x62\xba\xe3\x2c\x70\xd5\xfc".
      "\x78\x35\x36\x79\x5d\xb6\x0e\x35\x3d\x46\x87\xfb\xf5\x64\x1f".
      "\x3e\xfd\x2f\x1c\xbb\xed\x95\x2d\xd6\x63\xdc\xa7\x6a\x39\x8f".
      "\xbd\xcb\x79\x95\xe9\x45\xbf\xe4\x3e\x05\x55\x00\xdb\x33\x28".
      "\x3a\x6c\xe2\x35\xbb\xac\x70\x52\x2b\xac\x4e\x11\x44\x58\x16".
      "\x21\xb4\xae\x0d\x6a\xb9\xdc\x85\x5d\x90\x11\x26\x85\xdb\xc3".
      "\xf0\x38\x6f\x8a\xff\x12\xf0\xc9\x9e\xf0\xfc\xae\x94\x11\x4d".
      "\xce\x96\x29\x09\x6c\xf4\x2a\x6c\xda\x1e\x4c\x4a\xa2\x96\x5a".
      "\xef\xc6\x38\x5c\x60\xa2\x28\x13\x58\x73\x96\xde\x59\x2a\x57".
      "\x64\x6c\x14\x94\x8a\x2e\x8e\x21\x3f\xa2\x43\xde\xf6\x2d\x23".
      "\x74\x5c\xbd\x7a\x10\xdb\x17\xa8\x93\xd0\x74\x86\x9d\x33\x07".
      "\x48\xee\xac\x18\x6d\x64\x61\x7b\x61\x2b\xa4\xa2\xab\x99\x59".
      "\xbe\x19\xd7\x19\x41\x1e\x61\x87\xad\x40\x5b\x69\x8c\x32\xf5".
      "\xb6\x49\xbe\x1f\xad\xd8\x0f\x3e\xd9\x62\xac\x3a\x76\xde\x32".
      "\xa3\xb2\x41\x95\xad\x17\x23\xab\xa1\x37\x9c\xab\x73\x79\x70".
      "\xd6\x66\x0d\x6e\x4d\x8b\xa0\xac\xe3\x44\x1e\x0a\xee\xf0\x74".
      "\x64\xd8\x44\xd1\x6c\xa6\xd5\x36\x2e\xd9\x55\x6e\x90\x63\xb7".
      "\xf7\x8e\xc6\x28\xa3\x40\x00\x60\x9a\x3c\xfe\xff\x03\x30\x11".
      "\x18\x92\x2f\x5b\x23\xe1\x4e\x99\xe4\x82\xc9\x51\xe2\x15\x6a".
      "\x76\x5c\x67\xae\xa3\xa2\x9c\x85\x51\xe0\x44\x89\x63\xa5\x71".
      "\x99\xbc\x2d\x9c\xab\x9a\xfb\x20\x37\x58\xd6\x2d\x8b\x7d\x42".
      "\x13\x35\x44\x4c\x11\x97\x66\x27\x17\xac\x44\xe8\x6a\x03\x78".
      "\xa2\x88\xc6\x36\x71\x5a\x5a\x5a\x72\xa3\xe9\x72\x0c\x91\x31".
      "\xfc\xae\x7b\xa0\x75\x21\x0a\xc1\x4b\x95\xcb\xe3\xc2\xee\x03".
      "\x0f\xb8\xb2\x51\xc7\xc8\x9c\x8d\x6d\x3a\xe7\x4e\x2c\xaa\xeb".
      "\x5e\x49\x93\xe0\x8f\xa1\x54\x93\xe7\x7c\x5d\x31\xc7\x05\x00".
      "\x28\x14\x57\x47\xb3\x05\x2d\x17\x92\x28\x45\xee\x85\x3a\x59".
      "\xb6\xa6\x04\xc0\x5c\x07\x1f\xe6\x5b\x36\x53\x62\x82\x64\xd5".
      "\xb6\xf2\xf5\x67\x19\x11\xee\xd2\x70\xc5\x14\x63\xc1\x75\xe1".
      "\x24\xe5\x01\x59\x52\x7c\x88\x17\xb4\xe0\x15\xe9\x12\x05\xcd".
      "\x88\x7a\xd5\xea\x45\xc3\xbb\x65\xd4\xdd\x0d\xde\x36\x94\x98".
      "\x0d\x2c\xfb\x3c\x2f\x69\xd0\x28\xe2\x85\xd9\x27\xf3\x7a\xad".
      "\x50\x68\x96\x54\x5e\xeb\xbc\x2a\x74\xde\xf3\x4e\x8b\x27\x0a".
      "\xcf\x4c\x60\x40\xe8\xc5\x72\xab\x8c\xfd\xe9\xab\xff\x51\xe5".
      "\xd6\xea\x9e\x34\x73\xe1\xe6\xf8\x5b\xb1\x10\xf0\xf9\x2d\x23".
      "\x0e\xfe\xe5\xf4\x8d\xb6\x6d\x37\x14\xed\x54\x97\x92\x5c\x68".
      "\x40\x88\xf1\x43\x29\xef\x5e\x96\x77\xa2\xe8\x3c\xae\x7f\xb1".
      "\x99\x17\xa7\x0c\x6f\xe2\x43\x32\x9b\x14\x43\xf2\x15\x6b\x13".
      "\x10\x68\x56\x0b\xaa\x06\x2e\xc0\xf8\xde\x9e\x54\x9d\xba\xff".
      "\x76\x26\x6d\x5e\x9e\x88\x3a\x2b\x9b\x20\x43\xb9\x1a\x0e\x58".
      "\x65\xec\xdb\x9e\x97\xb8\xfb\x03\x6c\xb0\x7f\xa2\xf1\xf4\x27".
      "\x24\x21\x47\x51\x21\x40\x45\x28\x71\xf7\xa1\x6b\xbe\x0e\xc8".
      "\x3f\x9b\xda\x62\x9d\x73\xf7\x5f\x70\x6c\xba\x1e\xeb\x16\x5c".
      "\x2e\x44\x0a\x22\x02\x6c\xbe\xb9\x69\x93\xfd\xa5\x33\x26\x64".
      "\x24\x6c\xc2\x3d\x2f\xf3\xd1\x97\xde\x60\x43\x1c\x0d\x1b\x94".
      "\xb3\x48\x45\x7c\xd5\xd0\x71\x4d\xad\xbf\xa4\x0a\x22\x27\x04".
      "\x38\x84\x19\x66\x63\xf0\xf3\xfc\xb0\xf3\x1d\xea\xba\xb9\xe4".
      "\xe5\x80\xed\xe3\xf1\x78\x24\xc3\x25\x27\x71\x81\xc2\xec\x54".
      "\xed\xcc\x63\xf7\x39\xcd\x83\xdf\x32\x88\xc0\x3b\xd4\x62\xb8".
      "\xea\x34\xd8\xcf\xbc\x3a\x89\x38\x64\x60\x44\xde\xb6\x76\x59".
      "\xb1\x95\x6a\x26\x08\xf0\xf4\x71\x25\x8b\xf8\x81\xdd\x0d\x2f".
      "\x8c\xe2\x70\xc2\x96\xc2\xd8\x9b\xe4\x3f\xec\x8b\xfd\xbd\xc9".
      "\x36\x33\xb7\xbc\x59\x37\x19\x09\x30\x5e\xef\x67\xae\x67\x48".
      "\x72\x0b\xf4\x2a\x82\xff\xcb\xd7\xd9\x9d\x6d\x7c\xa6\x20\x42".
      "\x50\x2b\x0a\x2f\x45\x99\x5b\x76\x6d\x99\x39\xa9\xb6\x32\x06".
      "\x11\xf8\x19\xd1\x3f\xc0\xd6\x1f\x67\xfa\xd5\xae\x7a\x71\x8c".
      "\xbc\x3d\xb4\x5f\x5c\x81\x7c\xa1\x39\x70\x0a\x17\x24\xb7\x22".
      "\x86\x50\xd8\x1f\xc8\x6c\x59\x9a\xdc\xf0\x71\x01\xda\xd8\x53".
      "\x98\x1c\x73\x36\xf1\x09\x86\xc9\xa7\x26\x25\xc0\x03\x3e\x13".
      "\x4e\x29\xeb\xf0\x8d\xe3\x38\x03\x54\xee\x37\xfb\x51\x2e\xb4".
      "\xf6\x12\x1f\xb2\x8c\x66\x75\x00\x30\x5b\xef\x59\xf9\x63\xa9".
      "\x74\x07\x91\xe4\x9c\xb7\xc9\x89\xd9\xa9\x51\x93\xcb\xb1\xa7".
      "\x64\x08\x79\x8f\xb4\x6d\x09\xd7\xc5\xbf\x0a\xdb\x50\xe0\x1c".
      "\x83\xca\xf8\xcf\xa7\x81\xbb\x0b\xe6\xcf\x1b\x0e\x0a\xe0\xcd".
      "\x68\xe2\xde\xc4\x2d\xba\x55\xc7\xc7\x1e\x6c\x5e\xca\x9b\x20".
      "\x75\x96\x94\x92\x84\xec\xf5\x22\x25\x78\x67\xcd\xbe\x01\xfe".
      "\x53\xa5\xcc\x6a\x40\x33\x83\xa4\x7a\x44\x93\x0b\xf9\x4c\xb2".
      "\x95\xb6\x7e\x4b\xa4\xc8\x86\xfe\x8a\xf1\x77\x40\x56\x13\xc1".
      "\x31\x2c\x8c\x4a\xa8\x89\x61\x0c\x39\x33\x78\x8c\xd5\x50\x3b".
      "\x89\xc3\xd3\x80\x1c\xa7\xb6\x36\xc2\x00\x8d\x0a\x7f\xcc\xd3".
      "\x20\x74\x60\x70\x36\x7d\xda\xdc\xc4\x49\x04\xf0\xe6\x6c\xd1".
      "\xbe\xcb\xfb\xf1\xa2\xd6\xd4\xe4\x97\x3f\x35\x09\x5b\xda\x06".
      "\x6b\x6d\x86\x53\x23\x0c\x26\x51\x2a\x15\xaa\xe2\x73\xfb\xc7".
      "\x41\x54\xdc\x5d\x99\x0b\x0a\x1e\xd4\xdb\x70\xa3\x8e\xfd\x5b".
      "\xf0\xa8\x3e\x9b\xff\x57\x98\xbc\xd9\x2a\x56\xd3\x19\xf9\x0b".
      "\xd9\x67\x0f\x10\x9c\x23\xe5\x6b\x12\xc6\xb6\x4b\xd1\x0c\xe9".
      "\x45\x36\xdf\x54\x6f\xcc\xfe\xb5\xcc\xb9\xfe\xde\xc8\xb5\xc9".
      "\x04\x59\x61\x75\x1e\x72\x37\x54\xfd\xc6\xc3\x7e\x74\xae\x55".
      "\x31\x6a\xbc\x8a\xd8\x45\x91\xe2\x8d\x20\x97\x71\xe7\x55\xd6".
      "\x8a\xb8\x82\x2a\x27\x4f\xdc\x53\x89\x28\xf7\x3a\xfe\x07\xef".
      "\x60\xb2\x32\x7c\xbc\x13\xc4\x3d\xda\xd7\xfb\xb8\x61\x7d\x69".
      "\xae\x0e\x9a\x71\xd6\x00\x26\x97\xff\xdb\xe6\xbe\x45\x7a\xb5".
      "\x00\x31\xfd\x70\xcc\xd7\x34\x88\xe4\x05\x61\xf5\x72\x1d\x14".
      "\xf0\x7e\x90\xdb\x0e\xc7\xda\xd4\xf3\x99\xd4\x60\xd9\xa7\xc8".
      "\x5b\x33\x34\xb5\x23\x74\x2c\x5f\x6b\x56\x95\x9c\x1b\x2a\xac".
      "\xf9\xfe\x46\xc3\xf1\x9b\x24\x7e\x4b\xca\x25\x58\x41\x10\x63".
      "\xe8\xe7\x68\xda\xcc\xb6\x4d\x5b\x8f\xc9\xa9\x31\xeb\x5c\x2a".
      "\xcf\x9d\x89\xd5\x51\x93\x80\x30\xf4\xc9\x2c\x8c\xb8\x8c\x62".
      "\xd6\x33\xbd\x95\x9f\xfa\x19\xf2\x48\x28\x09\x73\xc9\x53\x61".
      "\x94\x3a\x62\x68\x6c\xc6\xd6\x0a\xb4\xae\x27\x96\xfb\x29\xd7".
      "\x46\x67\x11\x7a\xe8\x3a\x9a\x3f\xf4\x9a\x75\xed\x24\x67\x45".
      "\x79\xdc\x8b\x19\xf2\xef\x57\xaa\xc7\x84\xff\x9d\x2d\xc3\xa8".
      "\x85\x54\xb7\x9d\xe1\xd6\x2b\xe9\x31\x9d\x6c\xb8\x4e\x76\x50".
      "\x80\x44\x46\x8f\x5e\x7e\x20\xaa\xa0\x8a\x36\x6b\xef\xd1\x75".
      "\xf8\x3f\x20\xdd\x09\x73\xbf\xa5\xf7\xb4\x87\xb2\x44\xc0\x0f".
      "\x10\xc0\x95\x2e\x8a\x42\xfa\xc3\x49\x17\xb9\xb5\x1a\xc3\x80".
      "\x93\x0c\xd8\xe3\xcd\xa4\x38\x61\x7a\x22\x73\x8e\x32\x8f\x55".
      "\x9c\x91\x08\xd9\x65\xa9\x02\x28\xc6\x59\xc8\x51\x32\x20\x48".
      "\xea\x2c\xae\x0e\xa6\x35\x5b\xe2\x63\xf9\xf2\x9d\x5f\xe3\x45".
      "\xdc\x41\xba\xfb\x40\xcc\x8d\xde\x6c\x3d\x50\x97\x9d\x83\xa0".
      "\xda\x41\x61\xba\xaf\xf8\x74\xd2\x21\x7b\x09\xcc\x83\xe1\x08".
      "\x01\x04\x42\xce\xcb\xec\x1d\x6b\xb7\x6f\x0f\x4b\xd4\x53\x90".
      "\x55\x3b\xcf\x9f\x93\xb8\xad\xce\x5f\x13\x83\xb3\x89\x6f\x5a".
      "\x1b\xa4\xf5\x95\x4b\xb4\x22\x22\x1d\x35\xaa\xfa\xc7\x14\x8c".
      "\xcd\x50\x66\x14\x47\xff\x67\xb2\xf8\x12\x09\xb3\x8a\xe5\x7d".
      "\xb8\xc9\xe4\x89\xf7\xa4\xb5\x70\xfa\x2d\xeb\x95\x89\xec\xbb".
      "\x49\x59\xd2\xc1\x6d\x0e\x06\xe4\x5e\xd5\x13\x13\x0d\x72\x6e".
      "\xf0\x6d\xa9\xd5\xe7\x54\x68\x35\xcd\xd0\xd5\xa6\xe5\xb2\xe4".
      "\xb1\x19\xe4\xf1\xe3\x8a\x56\x4c\x3b\x3d\xb8\x03\xfe\x22\x2f".
      "\xc6\xdc\x88\x7b\xca\x5c\xc6\xdd\x17\x34\x08\x22\xf0\x17\x61".
      "\x0e\x60\x9c\xb4\x27\x57\x30\x6e\xb8\x4f\xdd\x25\x7b\xef\x9e".
      "\x8e\x88\x6b\xd8\x10\x23\xc2\x44\x53\x73\x64\x8f\x40\x22\xe1".
      "\xe8\xa2\xb0\x3f\x8a\x07\x66\xcd\x64\x4f\x9c\x1e\x89\x76\x04".
      "\x6d\xab\xc2\xbb\x16\x85\x80\x01\xa5\xb1\xe2\x12\x04\x2e\x39".
      "\x87\x8c\xee\xbc\xfb\x07\x6d\x03\x4c\x3a\xa5\x7b\x95\xd9\xd7".
      "\xd6\xee\x2b\xe9\xcb\xe6\xec\xa8\x84\x6a\x42\xf9\xb2\x25\xc8".
      "\xf3\x6a\xaa\x34\x3b\xd9\x72\xd9\x70\x81\x3b\xd4\x5e\x66\x97".
      "\x1b\xe6\x2b\x88\x71\x82\xa3\x8a\x98\xb0\x16\xd9\xbb\x97\x8b".
      "\x57\x79\x41\x56\x6e\xc2\x8f\xdf\xfa\x5b\xc7\x68\x5b\xb8\x09".
      "\x41\x31\x7c\x19\xe1\x95\x2e\x05\x4c\xac\x38\x81\xda\xb3\x8b".
      "\x3e\x1c\x79\x9a\x31\xac\x3e\x3d\x6d\xab\xf3\x5a\x5e\xc7\x6e".
      "\x8e\x39\xcd\x7b\x6f\x62\xee\xb9\x73\xdd\x82\x42\x6f\x09\xe4".
      "\xc3\xae\x92\xe8\x18\x99\xa0\x5e\xa2\x12\xf4\xe2\xe0\xe6\x95".
      "\x58\x3a\x45\xad\xfe\x23\x79\x5f\x82\xce\x95\x88\x73\xeb\x46".
      "\xc8\x00\xac\xc3\x2a\xdc\x7e\xab\x9b\xf8\xbb\x46\x5c\xa8\x46".
      "\xbc\xfd\x99\xae\x4c\xa7\x77\xeb\x7c\x58\xbf\xbb\x52\x68\x62".
      "\x3d\x0b\x79\x64\x38\x65\xa7\xcb\x7b\xe9\xb2\x33\xb5\x59\x52".
      "\x7b\x17\xb4\x02\x2b\x07\x0d\x3a\x11\x57\x92\xa5\x22\x2b\xbc".
      "\xe6\x97\x05\x12\x05\xe7\x91\xe3\xfa\xae\x15\xbe\x20\xe5\x5c".
      "\x71\x24\x80\x85\xc9\x66\xc1\x53\x5c\x8f\x08\xd4\x52\xe1\x10".
      "\xb6\xd6\x20\x08\x01\x79\x33\x9f\x1b\xbd\xa0\xab\x7c\xb1\xd9".
      "\xdc\xca\x44\x22\x49\xb7\xb7\x3d\x84\xac\x92\xf4\xfa\x0a\xc9".
      "\xc5\xb2\x42\x2b\x9a\x63\xbb\x8a\x82\x04\x2f\xf7\xe9\x30\x05".
      "\x67\x32\xd1\x41\x1a\x69\x6e\xb9\xf8\x5f\x6d\xb7\xe5\x4e\x85".
      "\x21\xfa\x16\x8a\x44\xfd\xf6\xd9\xa2\x5f\x68\x2b\xf3\xe2\x3c".
      "\x8a\x69\xd2\xc1\x38\xed\x83\xef\x0d\x53\x86\x93\x32\x23\xc6".
      "\x14\x0c\xb0\xb6\x6e\x77\xa4\x20\x0f\xb1\x6e\xe2\xce\xca\x6f".
      "\x93\x1c\x3a\x8f\xd0\xd2\x5a\x6e\x30\xd6\x8e\x5f\x4b\xa5\xef".
      "\xa9\x62\xeb\x28\xa0\x5e\x3f\xc1\xbc\x0a\x68\xab\xd7\xfa\xa2".
      "\xb7\x8f\x12\xb0\x99\xbc\x93\x20\xb8\x95\x8d\xca\xc7\xa7\xd9".
      "\x2e\x19\xac\x06\xb9\x4e\x56\x8e\x74\xef\x2a\x04\xd8\x75\x04".
      "\x38\x2a\xc7\xa0\xa4\x89\xf3\xa4\x8a\xd4\x2c\x2c\x58\x6f\x00".
      "\x03\x23\xb8\xaf\x02\x48\x7d\x50\x46\x6f\x5a\x08\x41\xe3\x56".
      "\x6d\xcb\xe2\x4f\xea\x8e\xab\x74\xcd\xf9\xef\xcf\xf9\x1e\xf1".
      "\xf8\xb9\x6c\xaa\x3b\x37\xd1\x21\x42\x67\xec\xd6\x44\x55\x33".
      "\xe8\x1d\xa4\x18\xf3\x73\x82\xb4\x50\x59\xc2\x34\x36\x05\xeb";

    $expect = Filesystem::readFile(dirname(__FILE__).'/base85/expect2.txt');
    $expect = trim($expect);

    $this->assertEqual(
      $expect,
      ArcanistBundle::encodeBase85($data));
  }

}
