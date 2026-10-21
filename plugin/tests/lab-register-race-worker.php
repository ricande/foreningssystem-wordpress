<?php
/**
 * One parallel worker for scripts/test-lab-race.sh. Not a WP-CLI file: each worker is its
 * own PHP process with its own database connection, like one browser request.
 *
 * Usage: php lab-register-race-worker.php <scenario> <start-epoch> <worker> [id] [detail]
 *
 * The detail is the contact version for update_contact and "<period-id>:<role-id>" for
 * board_coverage.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

[$script, $scenario, $start, $worker] = array_pad($argv, 4, '');
$target = (int) ($argv[4] ?? 0);
$detail = (string) ($argv[5] ?? '');

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'POST';

require '/var/www/html/wp-load.php';

use Foreningssystem\Application\People\ContactChanged;
use Foreningssystem\Application\People\MemberRegisterBusy;
use Foreningssystem\Domain\Board\BoardRuleException;
use Foreningssystem\Domain\Membership\AssociationDate;
use Foreningssystem\Domain\Membership\MembershipRuleException;
use Foreningssystem\Infrastructure\WordPress\WordpressBoard;
use Foreningssystem\Infrastructure\WordPress\WordpressPeople;

wp_set_current_user(1);
$service = WordpressPeople::service();
$board = WordpressBoard::service();

// Everything that can be loaded is loaded. Wait for the shared start time.
while (microtime(true) < (float) $start) {
    usleep(500);
}

$began = microtime(true);

try {
    switch ($scenario) {
        case 'add_period':
            $service->addPeriod($target, AssociationDate::fromIso('2025-01-01'));
            break;
        case 'register':
            $service->register('Race', 'Number', 'lab-race-number-' . $worker . '@example.test', 'LAB-RACE-NUMBER', 'ordinary', AssociationDate::fromIso('2025-01-01'));
            break;
        case 'update_contact':
            $service->updateContact($target, 'Race', 'Contact ' . $worker, 'lab-race-contact@example.test', null, AssociationDate::fromIso('2025-01-01'), 1, $detail);
            break;
        case 'board_coverage':
            [$periodId, $roleId] = array_map('intval', explode(':', $detail));

            if ((int) $worker === 1) {
                $service->endMembership($periodId, AssociationDate::fromIso('2025-06-30'));
            } else {
                $board->place($target, $roleId, AssociationDate::fromIso('2025-01-01'), null, '', '');
            }
            break;
        default:
            throw new \InvalidArgumentException('Unknown scenario.');
    }

    $outcome = 'saved';
} catch (MemberRegisterBusy) {
    $outcome = 'busy';
} catch (ContactChanged) {
    $outcome = 'changed';
} catch (MembershipRuleException | BoardRuleException $error) {
    $outcome = 'refused:' . $error->getMessage();
} catch (\Throwable $error) {
    $outcome = 'error:' . get_class($error) . ':' . $error->getMessage();
}

echo json_encode([
    'worker' => (int) $worker,
    'late' => round($began - (float) $start, 4),
    'outcome' => $outcome,
]) . "\n";
