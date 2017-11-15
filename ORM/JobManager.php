<?php

namespace Dtc\QueueBundle\ORM;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Dtc\QueueBundle\Doctrine\BaseJobManager;
use Dtc\QueueBundle\Entity\Job;
use Dtc\QueueBundle\Model\BaseJob;
use Dtc\QueueBundle\Model\RetryableJob;
use Symfony\Component\Process\Exception\LogicException;

class JobManager extends BaseJobManager
{
    use CommonTrait;
    protected static $saveInsertCalled = null;
    protected static $resetInsertCalled = null;

    public function countJobsByStatus($objectName, $status, $workerName = null, $method = null)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();

        $queryBuilder = $objectManager
            ->createQueryBuilder()
            ->select('count(a.id)')
            ->from($objectName, 'a')
            ->where('a.status = :status');

        if (null !== $workerName) {
            $queryBuilder->andWhere('a.workerName = :workerName')
                ->setParameter(':workerName', $workerName);
        }

        if (null !== $method) {
            $queryBuilder->andWhere('a.method = :method')
                ->setParameter(':method', $workerName);
        }

        $count = $queryBuilder->setParameter(':status', $status)
            ->getQuery()->getSingleScalarResult();

        if (!$count) {
            return 0;
        }

        return $count;
    }

    /**
     * @param string|null $workerName
     * @param string|null $method
     *
     * @return int Count of jobs pruned
     */
    public function pruneErroneousJobs($workerName = null, $method = null)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        $queryBuilder = $objectManager->createQueryBuilder()->delete($this->getArchiveObjectName(), 'j');
        $queryBuilder->where('j.status = :status')
            ->setParameter(':status', BaseJob::STATUS_ERROR);

        $this->addWorkerNameCriterion($queryBuilder, $workerName, $method);
        $query = $queryBuilder->getQuery();

        return intval($query->execute());
    }

    protected function resetSaveOk($function)
    {
        $objectManager = $this->getObjectManager();
        $splObjectHash = spl_object_hash($objectManager);

        if ('save' === $function) {
            $compare = static::$resetInsertCalled;
        } else {
            $compare = static::$saveInsertCalled;
        }

        if ($splObjectHash === $compare) {
            // Insert SQL is cached...
            $msg = "Can't call save and reset within the same process cycle (or using the same EntityManager)";
            throw new LogicException($msg);
        }

        if ('save' === $function) {
            static::$saveInsertCalled = spl_object_hash($objectManager);
        } else {
            static::$resetInsertCalled = spl_object_hash($objectManager);
        }
    }

    /**
     * @param string $workerName
     * @param string $method
     */
    protected function addWorkerNameCriterion(QueryBuilder $queryBuilder, $workerName = null, $method = null)
    {
        if (null !== $workerName) {
            $queryBuilder->andWhere('j.workerName = :workerName')->setParameter(':workerName', $workerName);
        }

        if (null !== $method) {
            $queryBuilder->andWhere('j.method = :method')->setParameter(':method', $method);
        }
    }

    protected function updateExpired($workerName = null, $method = null)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        $queryBuilder = $objectManager->createQueryBuilder()->update($this->getObjectName(), 'j');
        $queryBuilder->set('j.status', ':newStatus');
        $queryBuilder->where('j.expiresAt <= :expiresAt')
            ->setParameter(':expiresAt', new \DateTime());
        $queryBuilder->andWhere('j.status = :status')
            ->setParameter(':status', BaseJob::STATUS_NEW)
            ->setParameter(':newStatus', Job::STATUS_EXPIRED);

        $this->addWorkerNameCriterion($queryBuilder, $workerName, $method);
        $query = $queryBuilder->getQuery();

        return intval($query->execute());
    }

    protected function getJobCurrentStatus(\Dtc\QueueBundle\Model\Job $job)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        $queryBuilder = $objectManager->createQueryBuilder()->select('j.status')->from($this->getObjectName(), 'j');
        $queryBuilder->where('j.id = :id')->setParameter(':id', $job->getId());

        return $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Removes archived jobs older than $olderThan.
     *
     * @param \DateTime $olderThan
     */
    public function pruneArchivedJobs(\DateTime $olderThan)
    {
        return $this->removeOlderThan($this->getArchiveObjectName(),
                'updatedAt',
                $olderThan);
    }

    public function getJobCount($workerName = null, $method = null)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        $queryBuilder = $objectManager->createQueryBuilder();

        $queryBuilder = $queryBuilder->select('count(j)')->from($this->getObjectName(), 'j');

        $where = 'where';
        if (null !== $workerName) {
            if (null !== $method) {
                $queryBuilder->where($queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq('j.workerName', ':workerName'),
                                                $queryBuilder->expr()->eq('j.method', ':method')
                ))
                    ->setParameter(':method', $method);
            } else {
                $queryBuilder->where('j.workerName = :workerName');
            }
            $queryBuilder->setParameter(':workerName', $workerName);
            $where = 'andWhere';
        } elseif (null !== $method) {
            $queryBuilder->where('j.method = :method')->setParameter(':method', $method);
            $where = 'andWhere';
        }

        $dateTime = new \DateTime();
        // Filter
        $queryBuilder
            ->$where($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('j.whenAt'),
                                        $queryBuilder->expr()->lte('j.whenAt', ':whenAt')
            ))
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('j.expiresAt'),
                $queryBuilder->expr()->gt('j.expiresAt', ':expiresAt')
            ))
            ->andWhere('j.locked is NULL')
            ->setParameter(':whenAt', $dateTime)
            ->setParameter(':expiresAt', $dateTime);

        $query = $queryBuilder->getQuery();

        return $query->getSingleScalarResult();
    }

    /**
     * For ORM it's prudent to wrap things in a transaction.
     *
     * @param $i
     * @param $count
     * @param array $stalledJobs
     * @param $countProcessed
     */
    protected function runStalledLoop($i, $count, array $stalledJobs, &$countProcessed)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        try {
            $objectManager->beginTransaction();
            parent::runStalledLoop($i, $count, $stalledJobs, $countProcessed);
            $objectManager->commit();
        } catch (\Exception $exception) {
            $objectManager->rollback();

            // Try again
            parent::runStalledLoop($i, $count, $stalledJobs, $countProcessed);
        }
    }

    /**
     * Get Jobs statuses.
     */
    public function getStatus()
    {
        $result = [];
        $this->getStatusByEntityName($this->getObjectName(), $result);
        $this->getStatusByEntityName($this->getArchiveObjectName(), $result);

        $finalResult = [];
        foreach ($result as $key => $item) {
            ksort($item);
            foreach ($item as $status => $count) {
                if (isset($finalResult[$key][$status])) {
                    $finalResult[$key][$status] += $count;
                } else {
                    $finalResult[$key][$status] = $count;
                }
            }
        }

        return $finalResult;
    }

    /**
     * @param string $entityName
     */
    protected function getStatusByEntityName($entityName, array &$result)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();
        $result1 = $objectManager->getRepository($entityName)->createQueryBuilder('j')->select('j.workerName, j.method, j.status, count(j) as c')
            ->groupBy('j.workerName, j.method, j.status')->getQuery()->getArrayResult();

        foreach ($result1 as $item) {
            $method = $item['workerName'].'->'.$item['method'].'()';
            if (!isset($result[$method])) {
                $result[$method] = [BaseJob::STATUS_NEW => 0,
                    BaseJob::STATUS_RUNNING => 0,
                    RetryableJob::STATUS_EXPIRED => 0,
                    RetryableJob::STATUS_MAX_ERROR => 0,
                    RetryableJob::STATUS_MAX_STALLED => 0,
                    RetryableJob::STATUS_MAX_RETRIES => 0,
                    BaseJob::STATUS_SUCCESS => 0,
                    BaseJob::STATUS_ERROR => 0, ];
            }
            $result[$method][$item['status']] += intval($item['c']);
        }
    }

    /**
     * Get the next job to run (can be filtered by workername and method name).
     *
     * @param string $workerName
     * @param string $methodName
     * @param bool   $prioritize
     *
     * @return Job|null
     */
    public function getJob($workerName = null, $methodName = null, $prioritize = true, $runId = null)
    {
        /** @var EntityManager $objectManager */
        $objectManager = $this->getObjectManager();

        /** @var EntityRepository $repository */
        $repository = $this->getRepository();
        $queryBuilder = $repository->createQueryBuilder('j');
        $dateTime = new \DateTime();
        $queryBuilder
            ->select('j.id')
            ->where('j.status = :status')->setParameter(':status', BaseJob::STATUS_NEW)
            ->andWhere('j.locked is NULL')
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('j.whenAt'),
                        $queryBuilder->expr()->lte('j.whenAt', ':whenAt')
            ))
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('j.expiresAt'),
                        $queryBuilder->expr()->gt('j.expiresAt', ':expiresAt')
            ))
            ->setParameter(':whenAt', $dateTime)
            ->setParameter(':expiresAt', $dateTime);

        $this->addWorkerNameCriterion($queryBuilder, $workerName, $methodName);

        if ($prioritize) {
            $queryBuilder->add('orderBy', 'j.priority DESC, j.whenAt ASC');
        } else {
            $queryBuilder->orderBy('j.whenAt', 'ASC');
        }
        $queryBuilder->setMaxResults(1);

        /** @var QueryBuilder $queryBuilder */
        $query = $queryBuilder->getQuery();
        $jobs = $query->getResult();
        if (isset($jobs[0]['id'])) {
            return $this->takeJob($jobs[0]['id'], $runId);
        }

        return null;
    }

    protected function takeJob($jobId, $runId = null)
    {
        if ($jobId) {
            $repository = $this->getRepository();
            $queryBuilder = $repository->createQueryBuilder('j');
            $queryBuilder
                ->update()
                ->set('j.locked', ':locked')
                ->setParameter(':locked', true)
                ->set('j.lockedAt', ':lockedAt')
                ->setParameter(':lockedAt', new \DateTime())
                ->set('j.status', ':status')
                ->setParameter(':status', BaseJob::STATUS_RUNNING);
            if (null !== $runId) {
                $queryBuilder
                    ->set(':runId', $runId);
            }
            $queryBuilder->where('j.id = :id');
            $queryBuilder->andWhere('j.locked is NULL');
            $queryBuilder->setParameter(':id', $jobId);
            $resultCount = $queryBuilder->getQuery()->execute();

            if (1 === $resultCount) {
                return $repository->find($jobId);
            }
        }

        return null;
    }

    /**
     * Tries to update the nearest job as a batch.
     *
     * @param \Dtc\QueueBundle\Model\Job $job
     *
     * @return mixed|null
     */
    public function updateNearestBatch(\Dtc\QueueBundle\Model\Job $job)
    {
        $oldJob = null;
        $retries = 0;
        do {
            try {
                /** @var EntityManager $entityManager */
                $entityManager = $this->getObjectManager();
                $entityManager->beginTransaction();

                /** @var QueryBuilder $queryBuilder */
                $queryBuilder = $this->getRepository()->createQueryBuilder('j');
                $queryBuilder->select()
                    ->where('j.crcHash = :crcHash')
                    ->andWhere('j.status = :status')
                    ->setParameter(':status', BaseJob::STATUS_NEW)
                    ->setParameter(':crcHash', $job->getCrcHash())
                    ->orderBy('j.whenAt', 'ASC')
                    ->setMaxResults(1);
                $oldJobs = $queryBuilder->getQuery()->execute();

                if (empty($oldJobs)) {
                    return null;
                }
                $oldJob = $oldJobs[0];

                $oldJob->setPriority(max($job->getPriority(), $oldJob->getPriority()));
                $oldJob->setWhenAt(min($job->getWhenAt(), $oldJob->getWhenAt()));

                $entityManager->persist($oldJob);
                $entityManager->commit();
                $this->flush();
            } catch (\Exception $exception) {
                ++$retries;
                $entityManager->rollback();
            }
        } while (null === $oldJob && $retries < 5); // After 5 retries assume database is down or too much contention

        return $oldJob;
    }
}
