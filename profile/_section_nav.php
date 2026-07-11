<?php
$activeProfileSection = $activeProfileSection ?? 'profile';

$profileSections = [
    'profile' => [
        'label' => 'Profile',
        'href' => url('profile/index.php'),
        'icon' => 'user',
        'hint' => 'Account',
    ],
    'signature' => [
        'label' => 'Signature',
        'href' => url('profile/signature.php'),
        'icon' => 'mail',
        'hint' => 'Email footer',
    ],
    'templates' => [
        'label' => 'Templates',
        'href' => url('profile/templates.php'),
        'icon' => 'square_plus',
        'hint' => 'Reusable text',
    ],
    'notification' => [
        'label' => 'Notification',
        'href' => url('profile/notification.php'),
        'icon' => 'zap',
        'hint' => 'Sound',
    ],
];
?>
<div class="profile-section-nav">
    <?php foreach ($profileSections as $key => $section): ?>
        <a href="<?php echo e($section['href']); ?>" class="profile-section-card<?php echo $activeProfileSection === $key ? ' is-active' : ''; ?>"<?php echo $activeProfileSection === $key ? ' aria-current="page"' : ''; ?>>
            <span class="profile-section-card__icon"><?php echo lucide_icon_svg($section['icon'], ['size' => 18]); ?></span>
            <span class="profile-section-card__text">
                <strong><?php echo e($section['label']); ?></strong>
                <small><?php echo e($section['hint']); ?></small>
            </span>
        </a>
    <?php endforeach; ?>
</div>
