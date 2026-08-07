<?php
/**
 * The shell every platform system email renders into.
 *
 * Scope is mail the application sends as ITSELF to its own users -- password resets, signup
 * verification, invitations, and the dunning sequence. If your product also sends mail on a
 * customer's behalf, that needs its own neutral shell and its own From: this one carries your
 * branding, which is wrong on someone else's receipts.
 *
 * Email-client constraints, all deliberate:
 *  - Tables for layout, every style inline. No <style> block, no external CSS.
 *  - Fluid-hybrid width: width:100% + max-width:600px, with an MSO conditional pinning Outlook
 *    desktop to 600px. A hard width="600" overflows on phones.
 *  - Arial/Helvetica only. Poppins and Nunito are self-hosted and unavailable in mail clients.
 *  - The wordmark is live text next to the mark, so the header still reads when images are
 *    blocked (which is the default in plenty of clients).
 *  - Absolute asset and link URLs off APP_URL: app routes and assets exist only on the app host.
 *
 * Colours mirror public/css/base.css -- accent #4f46e5, ink #1c1917, ink-muted #57534e,
 * ink-subtle #78716c, border #e5ddd0, border-subtle #f0ebe3, paper #f5f4ff.
 *
 * @var string $preheader     Inbox-preview line. Hidden in the body.
 * @var string $title         <title> text; not rendered visually.
 * @var string $content       Pre-built block HTML from Framework\Accounts\Service\EmailBlocks.
 * @var string $footerReason  Why this person is receiving this email.
 */

use Framework\Brand;
use Framework\Host;

$appUrl  = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
$logoUrl = Host::appUrl('/img/logo-mark.png');
$appHost = Host::appHost();

$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?= $e($title) ?></title>
</head>
<body style="margin:0; padding:0; background-color:#f5f4ff; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;"><?= $e($preheader) ?></div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f4ff;">
<tr>
<td align="center" style="padding:32px 12px 12px 12px;">

<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; margin:0 auto; background-color:#ffffff; border:1px solid #e5ddd0; border-radius:8px;">

<tr>
<td style="padding:26px 40px 0 40px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td valign="middle" style="padding-right:9px;">
<img src="<?= $e($logoUrl) ?>" width="28" height="28" alt="" style="display:block; width:28px; height:28px; border:0; border-radius:4px;">
</td>
<td valign="middle" style="font-family:Arial,Helvetica,sans-serif; font-size:19px; font-weight:bold; letter-spacing:-0.2px; color:#1c1917;"><?= $e(Brand::name()) ?></td>
</tr>
</table>
</td>
</tr>

<tr>
<td style="padding:20px 40px 0 40px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr>
<td width="52" height="2" bgcolor="#4f46e5" style="background-color:#4f46e5; font-size:0; line-height:0;">&nbsp;</td>
<td height="2" bgcolor="#f0ebe3" style="background-color:#f0ebe3; font-size:0; line-height:0;">&nbsp;</td>
</tr>
</table>
</td>
</tr>

<?= $content ?>

<tr>
<td style="padding:36px 40px 32px 40px;">
<p style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.65; color:#57534e;">&mdash; The <?= $e(Brand::name()) ?> team</p>
</td>
</tr>

</table>
<!--[if mso]></td></tr></table><![endif]-->

</td>
</tr>

<tr>
<td align="center" style="padding:22px 12px 40px 12px;">
<!--[if mso]><table role="presentation" width="600" align="center"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; margin:0 auto;">
<tr>
<td align="center" style="padding:0 24px;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
<tr>
<td valign="middle" style="padding-right:7px;">
<img src="<?= $e($logoUrl) ?>" width="18" height="18" alt="" style="display:block; width:18px; height:18px; border:0; border-radius:3px;">
</td>
<td valign="middle" style="font-family:Arial,Helvetica,sans-serif; font-size:14px; font-weight:bold; letter-spacing:-0.1px; color:#78716c;"><?= $e(Brand::name()) ?></td>
</tr>
</table>

<p style="margin:12px 0 0 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.6; color:#a8a29e;"><?= $e($footerReason) ?></p>
<?php if ($appUrl !== '' && $appHost !== ''): ?>
<p style="margin:6px 0 0 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.6;"><a href="<?= $e($appUrl) ?>" style="color:#78716c; text-decoration:underline;"><?= $e($appHost) ?></a></p>
<?php endif; ?>

</td>
</tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td>
</tr>

</table>
</body>
</html>
