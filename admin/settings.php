<?php

$page_title = 'System Settings';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require '../includes/header.php';
require_role('admin');


/*
|--------------------------------------------------------------------------
| Default Settings
|--------------------------------------------------------------------------
*/

$defaults = [
    'appointment_duration' => '30',
    'cancellation_hours'   => '24',
    'reminder_hours'       => '24'
];

$settingsCollection = $mongoDb->selectCollection('system_settings');

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'save';

    if ($action === 'save' || $action === 'reset') {
        if ($action === 'reset') {
            $settings = $defaults;
        } else {
            $appointment_duration = (int)($_POST['appointment_duration'] ?? 0);
            $cancellation_hours = (int)($_POST['cancellation_hours'] ?? -1);
            $reminder_hours = (int)($_POST['reminder_hours'] ?? 0);

            $errors = [];

            if ($appointment_duration < 5 || $appointment_duration > 240) {
                $errors[] = 'Appointment duration must be between 5 and 240 minutes.';
            }

            if ($cancellation_hours < 0 || $cancellation_hours > 168) {
                $errors[] = 'Cancellation window must be between 0 and 168 hours.';
            }

            if ($reminder_hours < 1 || $reminder_hours > 168) {
                $errors[] = 'Reminder lead time must be between 1 and 168 hours.';
            }

            if ($errors) {
                foreach ($errors as $error) {
                    flash('danger', $error);
                }

                header('Location: settings.php');
                exit;
            }

            $settings = [
                'appointment_duration' => (string)$appointment_duration,
                'cancellation_hours'   => (string)$cancellation_hours,
                'reminder_hours'       => (string)$reminder_hours
            ];
        }

        try {
            foreach ($settings as $key => $value) {
                $settingsCollection->updateOne(
                    ['setting_key' => $key],
                    [
                        '$set' => [
                            'setting_key' => $key,
                            'setting_value' => $value
                        ]
                    ],
                    ['upsert' => true]
                );
            }

            audit(
                (int)$_SESSION['user_id'],
                $action === 'reset'
                    ? 'Restored default system settings'
                    : 'Updated system settings',
                'settings'
            );

            flash(
                'success',
                $action === 'reset'
                    ? 'Default system settings restored.'
                    : 'System settings saved successfully.'
            );
        } catch (Throwable $e) {
            error_log('Settings update error: ' . $e->getMessage());

            flash(
                'danger',
                $action === 'reset'
                    ? 'Unable to restore default settings.'
                    : 'Unable to save settings. Please try again.'
            );
        }

        header('Location: settings.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Current Settings
|--------------------------------------------------------------------------
*/

$savedSettings = [];

$settingDocuments = $settingsCollection->find()->toArray();

foreach ($settingDocuments as $document) {
    $item = (array)$document;

    if (isset($item['setting_key'])) {
        $savedSettings[$item['setting_key']] =
            (string)($item['setting_value'] ?? '');
    }
}

$appointment_duration = $savedSettings['appointment_duration']
    ?? $defaults['appointment_duration'];

$cancellation_hours = $savedSettings['cancellation_hours']
    ?? $defaults['cancellation_hours'];

$reminder_hours = $savedSettings['reminder_hours']
    ?? $defaults['reminder_hours'];

?>

<!-- Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">

            <i class="bi bi-sliders"></i>

            Administration

        </span>


        <h1 class="h3 fw-bold mt-2 mb-1">
            System Settings
        </h1>


        <p class="text-secondary mb-0">

            Configure appointment scheduling and reminder rules.

        </p>

    </div>


    <form
        method="post"
        onsubmit="return confirm('Restore all settings to their default values?');"
    >

        <?=csrf_field()?>

        <input
            type="hidden"
            name="action"
            value="reset"
        >


        <button
            type="submit"
            class="btn btn-outline-secondary"
        >

            <i class="bi bi-arrow-counterclockwise me-1"></i>

            Restore Defaults

        </button>

    </form>

</div>


<!-- Settings Form -->

<div class="row g-4">

    <div class="col-lg-8">

        <div class="card border-0 shadow-sm">

            <div class="card-header bg-white py-3">

                <div class="d-flex align-items-center gap-2">

                    <div
                        class="rounded-circle bg-primary-subtle text-primary p-2"
                    >

                        <i class="bi bi-calendar2-check"></i>

                    </div>


                    <div>

                        <h5 class="fw-bold mb-0">
                            Appointment Settings
                        </h5>

                        <small class="text-secondary">
                            Configure booking and cancellation behaviour.
                        </small>

                    </div>

                </div>

            </div>


            <div class="card-body p-4">

                <form method="post">

                    <?=csrf_field()?>

                    <input
                        type="hidden"
                        name="action"
                        value="save"
                    >


                    <!-- Appointment Duration -->

                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Default Slot Duration

                        </label>


                        <div class="input-group">

                            <input
                                type="number"
                                name="appointment_duration"
                                class="form-control"
                                min="5"
                                max="240"
                                step="5"
                                value="<?=h($appointment_duration)?>"
                                required
                            >

                            <span class="input-group-text">
                                minutes
                            </span>

                        </div>


                        <div class="form-text">

                            Default duration used when a doctor creates
                            a schedule without specifying another slot duration.

                            Allowed range: 5–240 minutes.

                        </div>

                    </div>


                    <!-- Cancellation Window -->

                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Cancellation Window

                        </label>


                        <div class="input-group">

                            <input
                                type="number"
                                name="cancellation_hours"
                                class="form-control"
                                min="0"
                                max="168"
                                value="<?=h($cancellation_hours)?>"
                                required
                            >

                            <span class="input-group-text">
                                hours
                            </span>

                        </div>


                        <div class="form-text">

                            Minimum time before an appointment when
                            cancellation rules should apply.

                            Allowed range: 0–168 hours.

                        </div>

                    </div>


                    <!-- Reminder -->

                    <div class="mb-4">

                        <label class="form-label fw-semibold">

                            Reminder Lead Time

                        </label>


                        <div class="input-group">

                            <input
                                type="number"
                                name="reminder_hours"
                                class="form-control"
                                min="1"
                                max="168"
                                value="<?=h($reminder_hours)?>"
                                required
                            >

                            <span class="input-group-text">
                                hours
                            </span>

                        </div>


                        <div class="form-text">

                            Defines how many hours before an appointment
                            the reminder process should target.

                            Allowed range: 1–168 hours.

                        </div>

                    </div>


                    <hr class="my-4">


                    <div class="d-flex justify-content-end">

                        <button
                            type="submit"
                            class="btn btn-primary px-4"
                        >

                            <i class="bi bi-check2-circle me-1"></i>

                            Save Settings

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>


    <!-- Current Configuration -->

    <div class="col-lg-4">

        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body p-4">

                <h5 class="fw-bold mb-3">

                    <i class="bi bi-info-circle me-1"></i>

                    Current Configuration

                </h5>


                <div class="d-flex justify-content-between align-items-center border-bottom py-3">

                    <span class="text-secondary">
                        Slot Duration
                    </span>

                    <strong>
                        <?=h($appointment_duration)?> min
                    </strong>

                </div>


                <div class="d-flex justify-content-between align-items-center border-bottom py-3">

                    <span class="text-secondary">
                        Cancellation
                    </span>

                    <strong>
                        <?=h($cancellation_hours)?> hrs
                    </strong>

                </div>


                <div class="d-flex justify-content-between align-items-center py-3">

                    <span class="text-secondary">
                        Reminder
                    </span>

                    <strong>
                        <?=h($reminder_hours)?> hrs
                    </strong>

                </div>

            </div>

        </div>


        <!-- Important Note -->

        <div class="alert alert-light border">

            <div class="d-flex gap-2">

                <i class="bi bi-lightbulb text-primary"></i>

                <div>

                    <strong class="d-block mb-1">
                        Configuration Note
                    </strong>

                    <small class="text-secondary">

                        Changes to these values affect future
                        appointment scheduling and reminder processing.
                        Existing appointment records are not modified.

                    </small>

                </div>

            </div>

        </div>

    </div>

</div>


<?php require '../includes/footer.php'; ?>