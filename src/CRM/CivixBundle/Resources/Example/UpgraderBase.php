<?php

use CRM_CivixBundle_Resources_Example_ExtensionUtil as E;

/**
 * Class CRM_CivixBundle_Resources_Example_UpgraderBase
 *
 * This is the de facto template for "CRM_*_Upgrader_Base" classes.
 *
 * Note: Unlike most civix classes, this lives in the global/top-level
 * namespace because that's a closer match to the actual output.
 */
class CRM_CivixBundle_Resources_Example_UpgraderBase {

  /**
   * @var CRM_CivixBundle_Resources_Example_UpgraderBase
   */
  public static $instance;

  /**
   * @var CRM_Queue_TaskContext
   */
  protected $ctx;

  /**
   * @var string
   *   eg 'com.example.myextension'
   */
  protected $extensionName;

  /**
   * @var string
   *   full path to the extension's source tree
   */
  protected $extensionDir;

  /**
   * @var array
   *   sorted numerically
   */
  private $revisions;

  /**
   * @var bool
   *   Flag to clean up extension revision data in civicrm_setting
   */
  private $revisionStorageIsDeprecated = FALSE;

  /**
   * Obtain a reference to the active upgrade handler.
   */
  public static function instance() {
    if (!self::$instance) {
      self::$instance = new static(E::LONG_NAME, E::path());
    }
    return self::$instance;
  }

  /**
   * Adapter that lets you add normal (non-static) member functions to the queue.
   *
   * Note: Each upgrader instance should only be associated with one
   * task-context; otherwise, this will be non-reentrant.
   *
   * ```
   * CRM_CivixBundle_Resources_Example_UpgraderBase::_queueAdapter($ctx, 'methodName', 'arg1', 'arg2');
   * ```
   */
  public static function _queueAdapter() {
    $instance = self::instance();
    $args = func_get_args();
    $instance->ctx = array_shift($args);
    $instance->queue = $instance->ctx->queue;
    $method = array_shift($args);
    return call_user_func_array([$instance, $method], $args);
  }

  /**
   * CRM_CivixBundle_Resources_Example_UpgraderBase constructor.
   *
   * @param $extensionName
   * @param $extensionDir
   */
  public function __construct($extensionName, $extensionDir) {
    $this->extensionName = $extensionName;
    $this->extensionDir = $extensionDir;
  }

  // ******** Task helpers ********

  // civix:inline_trait
  use CRM_CivixBundle_Resources_Example_UpgraderTasksTrait;

  /**
   * Syntactic sugar for enqueuing a task which calls a function in this class.
   *
   * The task is weighted so that it is processed
   * as part of the currently-pending revision.
   *
   * After passing the $funcName, you can also pass parameters that will go to
   * the function. Note that all params must be serializable.
   */
  public function addTask($title) {
    $args = func_get_args();
    $title = array_shift($args);
    $task = new CRM_Queue_Task(
      [get_class($this), '_queueAdapter'],
      $args,
      $title
    );
    return $this->queue->createItem($task, ['weight' => -1]);
  }

  // ******** Revision-tracking helpers ********

  /**
   * Determine if there are any pending revisions.
   *
   * @return bool
   */
  public function hasPendingRevisions() {
    $revisions = $this->getRevisions();
    $currentRevision = $this->getCurrentRevision();

    if (empty($revisions)) {
      return FALSE;
    }
    if (empty($currentRevision)) {
      return TRUE;
    }

    return ($currentRevision < max($revisions));
  }

  /**
   * Add any pending revisions to the queue.
   *
   * @param CRM_Queue_Queue $queue
   */
  public function enqueuePendingRevisions(CRM_Queue_Queue $queue) {
    $this->queue = $queue;

    $currentRevision = $this->getCurrentRevision();
    foreach ($this->getRevisions() as $revision) {
      if ($revision > $currentRevision) {
        $title = E::ts('Upgrade %1 to revision %2', [
          1 => $this->extensionName,
          2 => $revision,
        ]);

        // note: don't use addTask() because it sets weight=-1

        $task = new CRM_Queue_Task(
          [get_class($this), '_queueAdapter'],
          ['upgrade_' . $revision],
          $title
        );
        $this->queue->createItem($task);

        $task = new CRM_Queue_Task(
          [get_class($this), '_queueAdapter'],
          ['setCurrentRevision', $revision],
          $title
        );
        $this->queue->createItem($task);
      }
    }
  }

  /**
   * Get a list of revisions.
   *
   * @return array
   *   revisionNumbers sorted numerically
   */
  public function getRevisions() {
    if (!is_array($this->revisions)) {
      $this->revisions = [];

      $clazz = new ReflectionClass(get_class($this));
      $methods = $clazz->getMethods();
      foreach ($methods as $method) {
        if (preg_match('/^upgrade_(.*)/', $method->name, $matches)) {
          $this->revisions[] = $matches[1];
        }
      }
      sort($this->revisions, SORT_NUMERIC);
    }

    return $this->revisions;
  }

  public function getCurrentRevision() {
    $revision = CRM_Core_BAO_Extension::getSchemaVersion($this->extensionName);
    if (!$revision) {
      $revision = $this->getCurrentRevisionDeprecated();
    }
    return $revision;
  }

  private function getCurrentRevisionDeprecated() {
    $key = $this->extensionName . ':version';
    if ($revision = \Civi::settings()->get($key)) {
      $this->revisionStorageIsDeprecated = TRUE;
    }
    return $revision;
  }

  public function setCurrentRevision($revision) {
    CRM_Core_BAO_Extension::setSchemaVersion($this->extensionName, $revision);
    // clean up legacy schema version store (CRM-19252)
    $this->deleteDeprecatedRevision();
    return TRUE;
  }

  private function deleteDeprecatedRevision() {
    if ($this->revisionStorageIsDeprecated) {
      $setting = new CRM_Core_BAO_Setting();
      $setting->name = $this->extensionName . ':version';
      $setting->delete();
      CRM_Core_Error::debug_log_message("Migrated extension schema revision ID for {$this->extensionName} from civicrm_setting (deprecated) to civicrm_extension.\n");
    }
  }

  // ******** Hook delegates ********

  /**
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
   */
  public function onInstall() {
    $files = glob($this->extensionDir . '/sql/*_install.sql');
    if (is_array($files)) {
      foreach ($files as $file) {
        $this->executeSqlFile($file);
      }
    }
    $files = glob($this->extensionDir . '/sql/*_install.mysql.tpl');
    if (is_array($files)) {
      foreach ($files as $file) {
        $this->executeSqlTemplate($file);
      }
    }
    $files = glob($this->extensionDir . '/xml/*_install.xml');
    if (is_array($files)) {
      foreach ($files as $file) {
        $this->executeCustomDataFileByAbsPath($file);
      }
    }
    if (is_callable([$this, 'install'])) {
      $this->install();
    }
  }

  /**
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_postInstall
   */
  public function onPostInstall() {
    $revisions = $this->getRevisions();
    if (!empty($revisions)) {
      $this->setCurrentRevision(max($revisions));
    }
    if (is_callable([$this, 'postInstall'])) {
      $this->postInstall();
    }
  }

  /**
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_uninstall
   */
  public function onUninstall() {
    $files = glob($this->extensionDir . '/sql/*_uninstall.mysql.tpl');
    if (is_array($files)) {
      foreach ($files as $file) {
        $this->executeSqlTemplate($file);
      }
    }
    if (is_callable([$this, 'uninstall'])) {
      $this->uninstall();
    }
    $files = glob($this->extensionDir . '/sql/*_uninstall.sql');
    if (is_array($files)) {
      foreach ($files as $file) {
        CRM_Utils_File::sourceSQLFile(CIVICRM_DSN, $file);
      }
    }
  }

  /**
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
   */
  public function onEnable() {
    // stub for possible future use
    if (is_callable([$this, 'enable'])) {
      $this->enable();
    }
  }

  /**
   * @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_disable
   */
  public function onDisable() {
    // stub for possible future use
    if (is_callable([$this, 'disable'])) {
      $this->disable();
    }
  }

  public function onUpgrade($op, CRM_Queue_Queue $queue = NULL) {
    switch ($op) {
      case 'check':
        return [$this->hasPendingRevisions()];

      case 'enqueue':
        return $this->enqueuePendingRevisions($queue);

      default:
    }
  }

}