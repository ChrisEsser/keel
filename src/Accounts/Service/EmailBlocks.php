<?php

declare(strict_types=1);

namespace Keel\Accounts\Service;

// The block vocabulary shared by both transactional email shells. Call sites compose meaning --
// heading, paragraph, button -- and never write table markup, so the button style (and
// everything else) is controlled in exactly one place.
//
// views/emails/layout.php is the shell that consumes these blocks. An application that sends mail
// as something other than itself -- on behalf of a customer, say -- adds a second shell beside it
// and changes only the accent: keep the ink greys, the spacing and the 40px gutter identical, so
// a block looks the same wherever it lands.
//
// Every block appends to TWO parallel representations: the HTML row and its plaintext
// equivalent. That's deliberate rather than generating text from the HTML afterwards --
// EmailBodyPreparer::toText() exists, but it has no <td> or </table> handling, so on a
// table-based layout it runs adjacent cells together and drags the header and footer chrome
// into the body. Building both as we go costs a few lines and gives text output that was
// actually designed.
//
// All input is escaped on the way in. Form-submission notices (SiteController) and order
// confirmations render visitor and buyer input through details() and lineItems(), so this is a
// real injection boundary, not a formality.
final class EmailBlocks
{
    // Platform indigo (base.css --accent). The site shell passes SITE_ACCENT instead.
    public const PLATFORM_ACCENT = '#4f46e5';

    // Near-black. Deliberately not a colour: a website's own mail must not look like it belongs
    // to anyone's brand, least of all ours. See views/emails/site-layout.php.
    public const SITE_ACCENT = '#18181b';

    /** @var list<string> */
    private array $html = [];

    /** @var list<string> */
    private array $text = [];

    private string $preheader = '';

    private const FONT = 'font-family:Arial,Helvetica,sans-serif;';

    public function __construct(private string $accent = self::PLATFORM_ACCENT) {}

    // Inbox-preview text. Falls back to the first paragraph, which is the right line often
    // enough that most call sites shouldn't have to think about it.
    public function preheader(string $text): static
    {
        $this->preheader = trim($text);
        return $this;
    }

    public function heading(string $text): static
    {
        $this->html[] = $this->row('30px 40px 0 40px',
            '<h1 style="margin:0; ' . self::FONT . ' font-size:22px; line-height:1.35; font-weight:bold; color:#1c1917;">'
            . $this->e($text) . '</h1>');

        $this->text[] = $text;
        return $this;
    }

    public function paragraph(string $text): static
    {
        $this->html[] = $this->row('16px 40px 0 40px',
            '<p style="margin:0; ' . self::FONT . ' font-size:15px; line-height:1.65; color:#57534e;">'
            . $this->e($text) . '</p>');

        $this->text[] = $text;
        return $this;
    }

    // The one button style in the product's transactional mail. Bulletproof pattern: the
    // background sits on a <td bgcolor> as well as the inline style, because Outlook drops
    // background-color on an <a>.
    public function button(string $label, string $url): static
    {
        $this->html[] = $this->row('26px 40px 0 40px',
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td align="center" bgcolor="' . $this->accent . '" style="background-color:' . $this->accent
            . '; border-radius:6px;">'
            . '<a href="' . $this->e($url) . '" style="display:inline-block; padding:13px 28px; ' . self::FONT
            . ' font-size:15px; font-weight:bold; line-height:1; color:#ffffff; text-decoration:none; border-radius:6px;">'
            . $this->e($label) . '</a>'
            . '</td></tr></table>');

        $this->text[] = $label . ':' . "\n" . $url;
        return $this;
    }

    // The same URL again as selectable text. Worth the duplication on anything time-limited --
    // a reset link the recipient can't click is a support ticket. Adds nothing to the plaintext
    // part, where button() already emitted the bare URL.
    public function linkFallback(string $url): static
    {
        $this->html[] = $this->row('22px 40px 0 40px',
            '<p style="margin:0 0 6px 0; ' . self::FONT . ' font-size:13px; line-height:1.5; color:#78716c;">'
            . 'Or paste this link into your browser:</p>'
            . '<p style="margin:0; ' . self::FONT . ' font-size:13px; line-height:1.5; word-break:break-all;">'
            . '<a href="' . $this->e($url) . '" style="color:' . $this->accent . '; text-decoration:underline;">'
            . $this->e($url) . '</a></p>');

        return $this;
    }

    // A full-width inline image — a design mockup on a custom-request thread email. The image is
    // hosted (R2 public URL), not attached, so nothing here uploads binary. Width-capped so a large
    // export doesn't blow the 600px shell; $url must be an absolute https URL.
    public function image(string $url, string $alt = ''): static
    {
        $this->html[] = $this->row('22px 40px 0 40px',
            '<img src="' . $this->e($url) . '" alt="' . $this->e($alt) . '"'
            . ' style="display:block; width:100%; max-width:520px; height:auto; border-radius:6px; border:1px solid #f0ebe3;">');

        $this->text[] = ($alt !== '' ? $alt . ': ' : '') . $url;
        return $this;
    }

    // Smaller, quieter text: expiry windows, "ignore this if it wasn't you", consequences.
    public function note(string $text): static
    {
        $this->html[] = $this->row('22px 40px 0 40px',
            '<p style="margin:0; ' . self::FONT . ' font-size:13px; line-height:1.6; color:#78716c;">'
            . $this->e($text) . '</p>');

        $this->text[] = $text;
        return $this;
    }

    public function divider(): static
    {
        $this->html[] = $this->row('28px 40px 0 40px',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td height="1" bgcolor="#f0ebe3" style="background-color:#f0ebe3; font-size:0; line-height:0;">&nbsp;</td></tr>'
            . '</table>');

        $this->text[] = str_repeat('-', 48);
        return $this;
    }

    /**
     * Label/value rows in a tinted panel. Built for form-submission notifications, where both
     * halves are untrusted visitor input -- hence the escaping and the word-break, without which
     * one long unbroken value blows the table out on a phone.
     *
     * An ordered LIST of [label, value] pairs rather than a label => value map, for two reasons a
     * map gets wrong: a form may legitimately have two fields sharing a label (a map drops one),
     * and a numeric-looking label like "2024" would silently become an int key.
     *
     * @param list<array{0:string,1:string}> $rows
     */
    public function details(array $rows, ?string $caption = null): static
    {
        if ($rows === []) return $this;

        $cells = '';
        $lastIndex = array_key_last($rows);
        foreach ($rows as $i => [$label, $value]) {
            $isLast = $i === $lastIndex;
            $cells .= '<tr>'
                . '<td width="120" valign="top" style="padding:' . ($isLast ? '0 12px 0 0' : '0 12px 9px 0')
                . '; ' . self::FONT . ' font-size:13px; line-height:1.5; color:#78716c; word-break:break-word;">'
                . $this->e($label) . '</td>'
                . '<td valign="top" style="padding:' . ($isLast ? '0' : '0 0 9px 0') . '; ' . self::FONT
                . ' font-size:13px; line-height:1.5; color:#1c1917; word-break:break-word;">'
                . nl2br($this->e($value)) . '</td>'
                . '</tr>';
        }

        $captionHtml = $caption === null ? '' :
            '<p style="margin:0 0 14px 0; ' . self::FONT . ' font-size:12px; font-weight:bold; letter-spacing:0.6px;'
            . ' text-transform:uppercase; color:#a8a29e;">' . $this->e($caption) . '</p>';

        $this->html[] = $this->row('24px 40px 0 40px',
            $captionHtml
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
            . ' style="background-color:#faf7f3; border:1px solid #f0ebe3; border-radius:6px;">'
            . '<tr><td style="padding:14px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $cells . '</table>'
            . '</td></tr></table>');

        if ($caption !== null) $this->text[] = strtoupper($caption);
        foreach ($rows as [$label, $value]) {
            $this->text[] = $label . ': ' . $value;
        }

        return $this;
    }

    /**
     * An order's line items, then a ruled total. Quantity and name on the left, money
     * right-aligned; the money column is width-constrained and nowrap so a long product name
     * wraps instead of pushing the amount off the card.
     *
     * @param list<array{name:string,qty:int,amount:string}> $items
     */
    public function lineItems(array $items, string $total, string $totalLabel = 'Total'): static
    {
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr>'
                . '<td valign="top" style="padding:0 12px 10px 0; ' . self::FONT
                . ' font-size:14px; line-height:1.5; color:#1c1917; word-break:break-word;">'
                . $this->e((string) $item['qty']) . ' &times; ' . $this->e($item['name']) . '</td>'
                . '<td valign="top" align="right" style="padding:0 0 10px 0; ' . self::FONT
                . ' font-size:14px; line-height:1.5; color:#1c1917; white-space:nowrap;">'
                . $this->e($item['amount']) . '</td>'
                . '</tr>';
        }

        $this->html[] = $this->row('24px 40px 0 40px',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . $rows
            . '<tr><td colspan="2" height="1" bgcolor="#e4e4e7"'
            . ' style="background-color:#e4e4e7; font-size:0; line-height:0; padding:0;">&nbsp;</td></tr>'
            . '<tr>'
            . '<td style="padding:10px 12px 0 0; ' . self::FONT
            . ' font-size:14px; font-weight:bold; color:#1c1917;">' . $this->e($totalLabel) . '</td>'
            . '<td align="right" style="padding:10px 0 0 0; ' . self::FONT
            . ' font-size:14px; font-weight:bold; color:#1c1917; white-space:nowrap;">'
            . $this->e($total) . '</td>'
            . '</tr></table>');

        foreach ($items as $item) {
            $this->text[] = $item['qty'] . ' x ' . $item['name'] . ' - ' . $item['amount'];
        }
        $this->text[] = $totalLabel . ': ' . $total;

        return $this;
    }

    public function toHtml(): string
    {
        return implode("\n", $this->html);
    }

    public function toText(): string
    {
        return implode("\n\n", $this->text);
    }

    public function resolvedPreheader(): string
    {
        if ($this->preheader !== '') return $this->preheader;

        // First paragraph-ish line, trimmed to something an inbox will actually show.
        $first = $this->text[1] ?? $this->text[0] ?? '';
        return mb_substr(trim($first), 0, 160);
    }

    private function row(string $padding, string $inner): string
    {
        return '<tr><td style="padding:' . $padding . ';">' . $inner . '</td></tr>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
