<?php

namespace app\story;

use app\story\data\StaticResource as StoryData;

class Story {
  public static function main($args) {
    $i = reset($args);
    $matches = StoryData::find("story-{$i}.txt");
    if (empty($matches)) {
      echo "Could not find story with index: $i\n";
      return 1;
    }
    echo $matches[0]->read();
  }
}
