<?php namespace GO\Job;

use GO\Services\Filesystem;
use GO\Services\Interval;

use Cron\CronExpression;
use Swift_Attachment;
use Swift_Mailer;
use Swift_MailTransport;
use Swift_Message;

abstract class Job
{
  /**
   * The command
   *
   * @var string
   */
  protected $command;

  /**
   * The arguments to be passed to the command
   *
   * @var array
   */
  protected $args;

  /**
   * The compiled command
   *
   * @var string
   */
  protected $compiled;

  /**
   * The job start time
   *
   * @var int
   */
  protected $time;

  /**
   * The files where the output has to be sent
   *
   * @var array
   */
  protected $outputs = [];

  /**
   * The emails where the output has to be sent
   *
   * @var array
   */
  private $emails = [];

  /**
   * The email address used to send the email
   *
   * @var array
   */
  private $emailFrom = ['cronjob@server.my' => 'My Email Server'];

  /**
   * Instance of
   *
   * @var Cron\CronExpression
   */
  public $execution;

  /**
   * Bool that defines if the command has to run in backgroud
   *
   * @var bool
   */
  public $runInBackground = true;

  /**
   * Bool that defines if the command passed the truth test
   *
   * @var bool
   */
  public $truthTest = true;

  /**
     * @var string
     */
    protected $lastExecutionFile;

  /**
   * Create a new instance of Job
   *
   * @param mixed $job
   * @param array $args
   * @return void
   */
  public function __construct($job, array $args = [])
  {
    $this->time = time();

    $this->command = $job;

    $this->args = $args;

    if (method_exists($this, 'init')) {
      $this->init();
    }

    $this->compiled = $this->build();
  }

  /**
   * Get command
   *
   * @return array
   */
  public function getCommand()
  {
    return $this->compiled;
  }

  /**
   * Get args
   *
   * @return array
   */
  public function getArgs()
  {
    return $this->args;
  }

  /**
   * Define when to run the job
   *
   * @param string expression
   * @return $this
   */
  public function at($expression)
  {
    $this->execution = CronExpression::factory($expression);

    return $this;
  }

  /**
   * Define the execution interval of the job
   *
   * @param int $interval - fallback to string '*'
   * @return GO\Services\Interval
   */
  public function every($interval = '*')
  {
    return new Interval($this, $interval);
  }

  /**
   * Define the file/s where to send the output of the job
   *
   * @param string/array $ouput
   * @param bool $mode
   * @return $this
   */
  public function output($output, $mode = false)
  {
    $this->outputs = is_array($output) ? $output : [$output];

    $this->mode = $mode === true ? 'a' : 'w';

    return $this;
  }

  /**
   * Get files output
   *
   * @return array
   */
  public function getFilesOutput()
  {
    return $this->outputs;
  }

  /**
   * Define the email address/es where to send the output of the job
   *
   * @param string/array $email
   * @return $this
   */
  public function email($email)
  {
    $this->emails = is_array($email) ? $email : [$email];

    $this->runInBackground = false;

    return $this;
  }

  /**
   * Get emails output
   *
   * @return array
   */
  public function getEmailsOutput()
  {
    return $this->emails;
  }

  /**
   * Check if the job is due to run
   *
   * @return bool
   */
  public function isDue()
  {
    if ($this->lastExecutionFile && is_readable($this->lastExecutionFile)) {
            $lastExecution = file_get_contents($this->lastExecutionFile);
            $lastRunDate = $this->execution->getPreviousRunDate();
            return $lastRunDate->getTimestamp() > $lastExecution && $this->truthTest === true;
        }
    return $this->execution->isDue() && $this->truthTest === true;
  }

  /**
   * Abstract function build and compile the command
   *
   */
  abstract public function build();

  /**
   * Compile command - finalize with output redirections
   *
   * @param string $command
   * @return string
   */
  protected function compile($command)
  {
    if (count($this->args) > 0) {
      foreach ($this->args as $key => $value) {
        $command .= " {$key} \"{$value}\"";
      }
    }

    if (count($this->outputs) > 0) {
      $command .= ' | tee ';
      $command .= $this->mode === 'a' ? '-a ' : '';
      foreach ($this->outputs as $o) {
        $command .= $o.' ';
      }
    }

    $command .= ' > /dev/null 2>&1';
    if ($this->runInBackground === true) {
      $command .= ' &';
    }

    return $this->compiled = trim($command);
  }

  /**
   * Execute the job
   *
   * @return string - The output of the executed job
   */
  public function exec()
  {
    $this->compiled = $this->build();
    if (is_callable($this->compiled)) {
      $return = call_user_func($this->command, $this->args);
      foreach ($this->outputs as $output) {
        Filesystem::write($return, $output, $this->mode);
      }
    } else {
      throw new Exception("Only PHP callables supported");
    }

    if ($this->emails) {
      $this->sendEmails();
    }

    return $return;
  }

  /**
   * Send the output to an email address
   *
   * @return void
   */
  private function sendEmails()
  {
    $transport = Swift_MailTransport::newInstance();
    $mailer = Swift_Mailer::newInstance($transport);

    $message = Swift_Message::newInstance()
      ->setSubject('Cronjob execution')
      ->setFrom($this->emailFrom)
      ->setTo($this->emails)
      ->setBody('Cronjob output attached')
      ->addPart('<q>Cronjob output attached</q>', 'text/html');

    foreach ($this->outputs as $file) {
      $message->attach(Swift_Attachment::fromPath($file));
    }

    $mailer->send($message);
  }

  /**
   * Run the command in foreground
   *
   * @return void
   */
  public function runInForeground()
  {
    $this->runInBackground = false;
  }

  /**
   * Injected config from the scheduler
   *
   * @return void
   */
  public function setup(array $config)
  {
    if (isset($config['lastExecutionFile'])) {
            $this->lastExecutionFile = $config['lastExecutionFile'];
        }
    if (isset($config['emailFrom'])) {
      $this->emailFrom = $config['emailFrom'];
    }
  }

  /**
   * Delegate execution to truth test if it's due
   *
   * @return void
   */
  public function when($test)
  {
    if (!is_callable($test)) {
      throw new \Exception('InvalidArgumentException');
    }
    $this->truthTest = $test();

    return $this;
  }
}
