<?php declare(strict_types=1);
class QuorumChoreographer implements Choreographer {
    /**
     * @property Database
     */
    private $database;

    /**
     * @property PeerProvider
     */
    private $peerProvider;

    public function __construct(Database $database, PeerProvider $peerProvider) {
        $this->database = $database;
        $this->peerProvider = $peerProvider;
    }

    /**
     * Runs an instruction, possibly updating from the next node.
     * Returns the total number of instructions executed, including updates fetched from the next node.
     * 
     * @throw IntegrityException
     * @throw TooManyPeersDownException
     * @throw TooManyPeersLockedException
     * @throw StateHashMismatchException
     * @throw PeerLockedException
     */
    public function runInstruction(Instruction $instruction, Transaction $transactionReceived = null) : int {
        $lockAcquired = $this->database->lock();

        // If the lock failed, we can still read the last sequence number.
        // And if the lock succeeded, we have the right sequence number.
        $lastSequenceNumber = $this->database->getLastSequenceNumber();

        // However, if we can't acquire a lock, we can't go through with the instruction.
        // Which doesn't mean we can't provide data to another peer who would need it.
        // That's why we return the $lastSequenceNumber anyway
        if (!$lockAcquired) {
            throw new PeerLockedException(
                $this->peerProvider->getLocalPeer(),
                $lastSequenceNumber
            );
        }

        $transaction = new MutableTransaction($this->peerProvider->listAllPeers(), $transactionReceived);
        $transaction->markPeerReady($this->peerProvider->getLocalPeer(), $lastSequenceNumber);

        try {
            $result = $this->handleTransaction($instruction, $transactionReceived);
            $this->database->commit();

            return $result;
        }
        catch (Exception $e) {
            $this->database->rollback();

            throw $e;
        }
    }

    private function handleTransaction(Instruction $instruction, Transaction $transaction) {
        $this->ensureEnoughPeersAreLeft($transaction);

        $nextPeer = $this->pickNextPeer($transaction);
        if ($nextPeer === null) {
            // We're the last one on the list.
            // And since we've already checked that enough peers were available for quorum,
            // let's just go ahead and start writing.

            // TODO Might as well retry locked nodes. Since we have the quorum,
            // it should now be possible to acquire lock on a few more,
            // since other writers will wait before retrying.
            // The more nodes we get, the better.
            // Actually there's a good chance we could eventually get all of them,
            // unless there's a lot of people trying to write.

            // First, get the latest data from wherever is needed
            $missingInstructions = $this->pullMissingInstructions($transaction);
            $lastSequenceNumber = $this->runRecordedInstructions($missingInstructions);

            // We're all caugth up. Let's now get a new sequence number, and run the new instruction
            $lastSequenceNumber = $this->database->getLastSequenceNumber();
            $this->database->runInstruction($lastSequenceNumber + 1, $instruction);

            return count($missingInstructions) + 1;
        }
        else {
            // There's more peers on the list
            do {
                try {
                    $runResult = $this->remotePeerClient->runInstruction(
                        $this->getMyPeerId(),
                        $nextPeer,
                        $instruction
                    );

                    // If that peer didn't raise any exception, it means it executed the instruction.
                    // It also returned the missing transactions for us, including the latest instruction
                    // So let's catch up!
                    $this->runRecordedInstructions($runResult->getRecordedInstructions());

                    return count($runResult->getRecordedInstructions());
                }
                catch (PeerLockedException $e) {
                    $this->transaction->markPeerLocked($nextPeer, $e->getLastSequenceNumber());
                }
                catch (PeerDownException $e) {
                    $this->transaction->markPeerDown($nextPeer);
                }

                $this->ensureEnoughPeersAreLeft($transaction);
            } while (($nextPeer = $this->pickNextPeer($transaction)) !== null);
        }

        // This place should never be reached, since we throw an exception when not enough peers are left.
        // Reaching here indicates a bug in the algorithm.
        // But hey, IT is IT...
        throw new RuntimeException("This should be unreachable.");
    }

    /**
     * Returns all the instructions for which the sequence number > $lastKnownSequenceNumber
     * 
     * @return array<RecordedInstruction>
     */
    public function getInstructionsSince(int $sequenceNumber) : array {
        return $this->database->getRecordedInstructions($sequenceNumber);
    }

    /**
     * Run a list of recorded instructions against our database.
     * Check the state hashes along the way, and fail if any of them is wrong.
     * 
     * @throw StateHashMismatchException
     */
    private function runRecordedInstructions(array $recordedInstructionList) : void {
        foreach ($recordedInstructionList as $recordedInstruction) {
            $sequenceNumber = $recordedInstruction->getSequenceNumber();
            $instruction = $recordedInstruction->getInstruction();
            $expectedStateHash = $recordedInstruction->getStateHash();

            $actualStateHash = $this->database->runInstruction($sequenceNumber, $instruction);

            if ($actualStateHash !== $expectedStateHash) {
                // We're missing an instruction somewhere, and probably diverged from $nextPeer.
                // This needs human intervention.
                throw new StateHashMismatchException($nextPeer->getId(), $sequenceNumber);
            }
        }
    }

    /**
     * Returns a random peer that hasn't been checked yet, or false if no peer is left.
     * 
     * @return Peer|false
     */
    private function pickNextPeer(Transaction $transaction) {
        $peerId = $transaction->pickRandomUnmarkedPeerId();
        return $peerId !== false ? $this->peers[$peerId] : false;
    }

    /**
     * @throw TooManyPeersDownException
     * @throw TooManyPeersLockedException
     */
    private function ensureEnoughPeersAreLeft(Transaction $transaction) {
        $totalPeerCount = $transaction->countPeers();
        $lockedPeerCount = $transaction->countPeersLocked();
        $downPeerCount = $transaction->countPeersDown();

        $requiredPeersForQuorum = intdiv($totalPeerCount, 2) + 1;
        $upPeerCount = $totalPeerCount - $downPeerCount;
        $maximumPossiblePeersReady = $totalPeerCount - $lockedPeerCount - $downPeerCount;

        if ($upPeerCount < $requiredPeersForQuorum) {
            throw new TooManyPeersDownException();
        }
        else if ($maximumPossiblePeersReady < $requiredPeersForQuorum) {
            throw new TooManyPeersLockedException();
        }
    }

    /**
     * Compare our lastSequenceNumber to that of other nodes.
     * If we are missing some instructions, fetch them from a peer
     * that is most up to date.
     */
    private function pullLatestUpdates(Transaction $transaction) {
        $myStatus = $transaction->getLastSequenceNumber($this->peerProvider->getLocalPeer());

        $myLastKnownSequenceNumber = $myStatus->getLastSequenceNumber();
        $peersLastKnownSequenceNumber = $transaction->getLastSequenceNumber();

        if ($peersLastKnownSequenceNumber > $myLastKnownSequenceNumber) {
            $eligiblePeers = $transaction->getPeersAtSequenceNumber($peersLastKnownSequenceNumber);

            // Those peers were up a few moments ago. Hopefully we can still reach at least one of them
            foreach ($eligiblePeers as $peerId) {
                $peer = $this->peerProvider->getPeer($peerId);

                try {
                    return $this->remotePeerClient->getInstructionsSince($peer, $myLastKnownSequenceNumber);
                }
                catch (PeerDownException $e) {
                    // Well, try the next peer.
                    // Nothing to do here.
                }
            }
        }
        else {
            // Looks like we have the latest data.
            // Nothing to do here.
        }        
    }
}
