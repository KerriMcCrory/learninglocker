<?php

use Jenssegers\Mongodb\Model as Eloquent;

class Export extends Eloquent {

  /**
   * Our MongoDB collection used by the model.
   *
   * @var string
   */
  protected $collection = 'exports';

}