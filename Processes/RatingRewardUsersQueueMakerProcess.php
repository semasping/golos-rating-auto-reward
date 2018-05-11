<?php


namespace MyApp\Processes;


use GolosPhpEventListener\app\process\ProcessAbstract;
use GolosPhpEventListener\app\process\ProcessInterface;
use GrapheneNodeClient\Commands\CommandQueryData;
use GrapheneNodeClient\Commands\Single\GetContentCommand;
use GrapheneNodeClient\Connectors\WebSocket\GolosWSConnector;
use MyApp\Db\RedisManager;

/**
 *
 * @method RedisManager getDBManager()
 */
class RatingRewardUsersQueueMakerProcess extends ProcessAbstract
{
    protected $isRunning         = true;
    protected $rewardUserPercent = 80;
    protected $priority          = 16;
    protected $memoRatingReward  = 'Награждения авторов из Альтернативного ТОПа Голоса {post_link}';
    protected $postLink          = 'https://goldvoice.club/{category}/{author}/{permlink}';

    /**
     * run before process start
     *
     * @return void
     */
    public function init()
    {
        $this->setDBManager(new RedisManager());
    }


    public function initSignalsHandlers()
    {
        pcntl_signal(SIGTERM, [$this, 'signalsHandlers']);
        pcntl_signal(SIGINT, [$this, 'signalsHandlers']); //ctrl+c
        pcntl_signal(SIGHUP, [$this, 'signalsHandlers']); //restart process
    }

    public function signalsHandlers($signo, $signinfo)
    {
        echo PHP_EOL . ' --- process with pid=' . $this->getPid() . ' got signal=' . $signo . ' and signinfo='
            . print_r($signinfo, true);

        switch ($signo) {
            case SIGTERM:
                $this->isRunning = false;
                break;
            default:
        }
    }

    public function start()
    {
        pcntl_setpriority($this->priority, getmypid());

        $listenerId = $this->getId();
//        echo PHP_EOL . ' - RatingRewardQueueMakerProcess is running';
        $first = $this->getDBManager()->ratingPostRewardGetFirstFromQueue();
//        echo PHP_EOL . ' - first' . print_r($first, true);

        $rewardPart = round($this->rewardUserPercent / 100, 3);

        while ($this->isRunning && $first !== false) {

            $connector = new GolosWSConnector();

            $commandQuery = new CommandQueryData();
            $commandQuery->setParamByKey('0', $first['author']);//blockNum
            $commandQuery->setParamByKey('1', $first['permlink']);//onlyVirtual

            $command = new GetContentCommand($connector);
            $data = $command->execute(
                $commandQuery,
                'result'
            );

            $meta = json_decode($data['json_metadata'], true);
            $totalUsers = count($meta['users']);
            $postLink = str_replace(
                [
                    '{category}',
                    '{author}',
                    '{permlink}'
                ],
                [
                    $data['category'],
                    $data['author'],
                    $data['permlink']
                ],
                $this->postLink
            );
            $memoRatingReward = str_replace('{post_link}', $postLink, $this->memoRatingReward);
            if ($totalUsers > 0) {
                // got value in 1000 times more
                $gbgReward = floor(str_replace(' GBG', '', $first['sbd_payout']) * $rewardPart / $totalUsers * 1000);
                $golosReward = floor(str_replace(' GOLOS', '', $first['steem_payout']) * $rewardPart / $totalUsers * 1000);

                if ($gbgReward > 0 || $golosReward > 0) {
                    $rewards = [];
                    if ($gbgReward > 0) {
                        $rewards[] = number_format($gbgReward / 1000, 3, '.', '') . ' GBG';
                    }
                    if ($golosReward > 0) {
                        $rewards[] = number_format($golosReward / 1000, 3, '.', '') . ' GOLOS';
                    }
                    foreach ($meta['users'] as $user) {
                        $dataForReward = [
                            'author'  => $user,
                            'rewards' => $rewards,
                            'memo'    => $memoRatingReward,
                        ];
                        $this->getDBManager()->ratingUsersRewardAddToQueue($dataForReward);
                    }
                    $usersTotal = count($meta['users']);
                    echo PHP_EOL . date('Y.m.d H:i:s') . " - {$usersTotal} users added for reward from post /{$data['category']}/@{$data['author']}/{$data['permlink']}";
                }
            }
            $this->getDBManager()->ratingPostRewardRemovePostFromQueue($first);
            $first = $this->getDBManager()->ratingPostRewardGetFirstFromQueue();
        }

//        echo PHP_EOL . ' - RatingRewardQueueMakerProcess did work';
    }

    /**
     * ask process to start
     *
     * @return bool
     */
    public function isStartNeeded()
    {
        $status = $this->getStatus();
        return $status === ProcessInterface::STATUS_RUN
            || (
                $status === ProcessInterface::STATUS_STOPPED
                && $this->getMode() === ProcessInterface::MODE_REPEAT
                && $this->getDBManager()->ratingPostRewardGetQueueLength()
            );
    }

    /**
     * clear parent resourses in child process
     *
     * @return void
     */
    public function clearParentResources()
    {
    }
}