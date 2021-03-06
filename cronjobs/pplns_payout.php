#!/usr/bin/php
<?php

/*

Copyright:: 2013, Sebastian Grewe

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

 */

// Change to working directory
chdir(dirname(__FILE__));

// Include all settings and classes
require_once('shared.inc.php');

// Check if we are set as the payout system
if ($config['payout_system'] != 'pplns') {
  $log->logInfo("Please activate this cron in configuration via payout_system = pplns");
  exit(0);
}

// Fetch all unaccounted blocks
$aAllBlocks = $block->getAllUnaccounted('ASC');
if (empty($aAllBlocks)) {
  $log->logDebug("No new unaccounted blocks found");
  $monitoring->endCronjob($cron_name, 'E0011', 0, true, false);
}

$count = 0;
foreach ($aAllBlocks as $iIndex => $aBlock) {
  // We support some dynamic share targets but fall back to our fixed value
  // Re-calculate after each run due to re-targets in this loop
  if ($config['pplns']['shares']['type'] == 'blockavg' && $block->getBlockCount() > 0) {
      $pplns_target = round($block->getAvgBlockShares($aBlock['height'], $config['pplns']['blockavg']['blockcount']));
  } else if ($config['pplns']['shares']['type'] == 'dynamic' && $block->getBlockCount() > 0) {
      $pplns_target = round($block->getAvgBlockShares($aBlock['height'], $config['pplns']['blockavg']['blockcount']) * (100 - $config['pplns']['dynamic']['percent'])/100 + $aBlock['shares'] * $config['pplns']['dynamic']['percent']/100);
  } else {
    $pplns_target = $config['pplns']['shares']['default'];
  }

  if ($iLastBlockHeight = $setting->getValue('last_accounted_block_height')) {
    $aLastAccountedBlock = $block->getBlock($iLastBlockHeight);
  } else {
    $iLastBlockHeight = 0;
  }
  // Double payout detection
  if ( @$aLastAccountedBlock['confirmations'] != -1 || ( !$aBlock['accounted'] && $aBlock['height'] > $iLastBlockHeight )) {
    $iPreviousShareId = @$aAllBlocks[$iIndex - 1]['share_id'] ? $aAllBlocks[$iIndex - 1]['share_id'] : 0;
    $iCurrentUpstreamId = $aBlock['share_id'];
    if (!is_numeric($iCurrentUpstreamId)) {
      $log->logFatal("Block " . $aBlock['height'] . " has no share_id associated with it, not going to continue");
      $monitoring->endCronjob($cron_name, 'E0012', 1, true);
    }
    $iRoundShares = $share->getRoundShares($iPreviousShareId, $aBlock['share_id']);
    $iNewRoundShares = 0;
    $config['reward_type'] == 'block' ? $dReward = $aBlock['amount'] : $dReward = $config['reward'];
    $aRoundAccountShares = $share->getSharesForAccounts($iPreviousShareId, $aBlock['share_id']);

    $log->logInfo('Target: ' . $pplns_target . '; Shares: ' . $iRoundShares . '; Height: ' . $aBlock['height'] . '; Amount: ' . $aBlock['amount'] . '; Found by ID: ' . $aBlock['account_id']);

    if ($iRoundShares >= $pplns_target) {
      $log->logDebug("Matching or exceeding PPLNS target of $pplns_target with $iRoundShares");
      $iMinimumShareId = $share->getMinimumShareId($pplns_target, $aBlock['share_id']);
      // We need to go one ID lower due to `id >` or we won't match if minimum share ID == $aBlock['share_id']
      $aAccountShares = $share->getSharesForAccounts($iMinimumShareId - 1, $aBlock['share_id']);
      if (empty($aAccountShares)) {
        $log->logFatal("No shares found for this block, aborted! Block Height : " . $aBlock['height']);
        $monitoring->endCronjob($cron_name, 'E0013', 1, true);
      }
      foreach($aAccountShares as $key => $aData) {
        $iNewRoundShares += $aData['valid'];
      }
      $log->logInfo('Adjusting round to PPLNS target of ' . $pplns_target . ' shares used ' . $iNewRoundShares);
      $iRoundShares = $iNewRoundShares;
    } else {
      $log->logDebug("Not able to match PPLNS target of $pplns_target with $iRoundShares");
      // We need to fill up with archived shares
      // Grab the full current round shares since we didn't match target
      $aAccountShares = $aRoundAccountShares;
      if (empty($aAccountShares)) {
        $log->logFatal("No shares found for this block, aborted! Block height: " . $aBlock['height']);
        $monitoring->endCronjob($cron_name, 'E0013', 1, true);
      }

      // Grab only the most recent shares from Archive that fill the missing shares
      $log->logInfo('Fetching ' . ($pplns_target - $iRoundShares) . ' additional shares from archive');
      if (!$aArchiveShares = $share->getArchiveShares($pplns_target - $iRoundShares)) {
        $log->logError('Failed to fetch shares from archive, setting target to round total');
        $pplns_target = $iRoundShares;
      } else {
        // Add archived shares to users current shares, if we have any in archive
        if (is_array($aArchiveShares)) {
          $log->logDebug('Found shares in archive to match PPLNS target, calculating per-user shares');
          foreach($aAccountShares as $key => $aData) {
            if (array_key_exists($aData['username'], $aArchiveShares)) {
              $log->logDebug('Found user ' . $aData['username'] . ' in archived shares');
              $log->logDebug('  valid   : ' . $aAccountShares[$key]['valid'] . ' + ' . $aArchiveShares[$aData['username']]['valid'] . ' = ' . ($aAccountShares[$key]['valid'] + $aArchiveShares[$aData['username']]['valid']) );
              $log->logDebug('  invalid : ' . $aAccountShares[$key]['invalid'] . ' + ' . $aArchiveShares[$aData['username']]['invalid'] . ' = ' . ($aAccountShares[$key]['invalid'] + $aArchiveShares[$aData['username']]['invalid']) );
              $aAccountShares[$key]['valid'] += $aArchiveShares[$aData['username']]['valid'];
              $aAccountShares[$key]['invalid'] += $aArchiveShares[$aData['username']]['invalid'];
            }
          }
          // reverse payout
          if ($config['pplns']['reverse_payout']) {
             $aSharesData = NULL;
             foreach($aAccountShares as $key => $aData) {
               $aSharesData[$aData['username']] = $aData;
             }
             // Add users from archive not in current round
             foreach($aArchiveShares as $key => $aArchData) {
               if (!array_key_exists($aArchData['account'], $aSharesData)) {
                 $log->logDebug('Adding user ' . $aArchData['account'] . ' to round shares');
                 $log->logDebug('  valid   : ' . $aArchData['valid']);
                 $log->logDebug('  invalid   : ' . $aArchData['invalid']);
                 $aArchData['username'] = $aArchData['account'];
                 $aSharesData[$aArchData['account']] = $aArchData;
               }
             }
          $aAccountShares = $aSharesData;
          }
        }
        // We tried to fill up to PPLNS target, now we need to check the actual shares to properly payout users
        foreach($aAccountShares as $key => $aData) {
          $iNewRoundShares += $aData['valid'];
        }
      }
    }

    // We filled from archive but still are not able to match PPLNS target, re-adjust
    if ($iRoundShares < $iNewRoundShares) {
      $log->logInfo('Adjusting round target to ' . $iNewRoundShares);
      $iRoundShares = $iNewRoundShares;
    }

    // Table header for account shares
    $log->logInfo("ID\tUsername\tValid\tInvalid\tPercentage\tPayout\t\tDonation\tFee");

    // Loop through all accounts that have found shares for this round
    foreach ($aAccountShares as $key => $aData) {
      // Payout based on PPLNS target shares, proportional payout for all users
      $aData['percentage'] = round(( 100 / $iRoundShares) * $aData['valid'], 8);
      $aData['payout'] = round(( $aData['percentage'] / 100 ) * $dReward, 8);
      // Defaults
      $aData['fee' ] = 0;
      $aData['donation'] = 0;

      if ($config['fees'] > 0 && $aData['no_fees'] == 0)
        $aData['fee'] = round($config['fees'] / 100 * $aData['payout'], 8);
      // Calculate donation amount, fees not included
      $aData['donation'] = round($user->getDonatePercent($user->getUserId($aData['username'])) / 100 * ( $aData['payout'] - $aData['fee']), 8);

      // Verbose output of this users calculations
      $log->logInfo($aData['id'] . "\t" .
        $aData['username'] . "\t" .
        $aData['valid'] . "\t" .
        $aData['invalid'] . "\t" .
        number_format($aData['percentage'], 8) . "\t" .
        number_format($aData['payout'], 8) . "\t" .
        number_format($aData['donation'], 8) . "\t" .
        number_format($aData['fee'], 8));

      // Add full round share statistics, not just PPLNS
      foreach ($aRoundAccountShares as $key => $aRoundData) {
        if ($aRoundData['username'] == $aData['username'])
          if (!$statistics->updateShareStatistics($aRoundData, $aBlock['id']))
            $log->logError('Failed to update share statistics for ' . $aData['username'] . ': ' . $statistics->getCronError());
      }

      // Add PPLNS share statistics
      foreach ($aAccountShares as $key => $aRoundData) {
        if ($aRoundData['username'] == $aData['username']){
          if (@$statistics->getIdShareStatistics($aRoundData, $aBlock['id'])){
            if (!$statistics->updatePPLNSShareStatistics($aRoundData, $aBlock['id']))
            $log->logError('Failed to update pplns statistics for ' . $aData['username'] . ': ' . $statistics->getCronError());
          } else {
          if (!$statistics->insertPPLNSShareStatistics($aRoundData, $aBlock['id']))
            $log->logError('Failed to insert pplns statistics for ' . $aData['username'] . ': ' . $statistics->getCronError());
          }
        }
      }

      // Add new credit transaction
      if (!$transaction->addTransaction($aData['id'], $aData['payout'], 'Credit', $aBlock['id']))
        $log->logFatal('Failed to insert new Credit transaction to database for ' . $aData['username'] . ': ' . $transaction->getCronError());
      // Add new fee debit for this block
      if ($aData['fee'] > 0 && $config['fees'] > 0)
        if (!$transaction->addTransaction($aData['id'], $aData['fee'], 'Fee', $aBlock['id']))
          $log->logFatal('Failed to insert new Fee transaction to database for ' . $aData['username'] . ': ' . $transaction->getCronError());
      // Add new donation debit
      if ($aData['donation'] > 0)
        if (!$transaction->addTransaction($aData['id'], $aData['donation'], 'Donation', $aBlock['id']))
          $log->logFatal('Failed to insert new Donation transaction to database for ' . $aData['username'] . ': ' . $transaction->getCronError());
    }

    // Store this blocks height as last accounted for
    $setting->setValue('last_accounted_block_height', $aBlock['height']);

    // Move counted shares to archive before this blockhash upstream share
    if (!$share->moveArchive($iCurrentUpstreamId, $aBlock['id'], $iPreviousShareId))
      $log->logError('Failed to copy shares to archive table: ' . $share->getCronError() . ': ' . $share->getCronError());
    // Delete all accounted shares
    if (!$share->deleteAccountedShares($iCurrentUpstreamId, $iPreviousShareId)) {
      $log->logFatal("Failed to delete accounted shares from $iPreviousShareId to $iCurrentUpstreamId, aborting! Error: " . $share->getCronError());
      $monitoring->endCronjob($cron_name, 'E0016', 1, true);
    }
    // Mark this block as accounted for
    if (!$block->setAccounted($aBlock['id'])) {
      $log->logFatal("Failed to mark block as accounted! Aborting! Error: " . $block->getCronError());
      $monitoring->endCronjob($cron_name, 'E0014', 1, true);
    }
  } else {
    $log->logFatal('Potential double payout detected. Aborted.');
    $aMailData = array(
      'email' => $setting->getValue('system_error_email'),
      'subject' => 'Payout processing aborted',
      'Error' => 'Potential double payout detected. All payouts halted until fixed!',
      'BlockID' => $aBlock['id'],
      'Block Height' => $aBlock['height'],
      'Block Share ID' => $aBlock['share_id']
    );
    if (!$mail->sendMail('notifications/error', $aMailData))
      $log->logError("    Failed sending notifications: " . $notification->getCronError() . "\n");
    $monitoring->endCronjob($cron_name, 'E0015', 1, true);
  }
}

require_once('cron_end.inc.php');
?>
