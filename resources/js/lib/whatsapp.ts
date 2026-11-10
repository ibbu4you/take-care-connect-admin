/*
 | WhatsApp click-to-chat.
 |
 | Messages to businesses go from the team member's own WhatsApp, through a
 | wa.me link with the text already written. That needs no WhatsApp Business
 | API account, no per-message fee and no approved templates — which matters to
 | a non-profit — and the business sees a real person's number they can reply
 | to, which is what gets an answer from a shopkeeper.
 |
 | The wording lives here, in one place, so it reads the same whoever sends it.
 */

/**
 * The number in the international form wa.me needs: digits only, with the
 * country code.
 *
 * Numbers are typed however the caller typed them — "98765 43210",
 * "098765-43210", "+91 98765 43210". A bare ten-digit number is Indian; a
 * leading trunk zero is dropped. Returns null when what is left cannot be a
 * phone number, so no button is drawn for "call the office".
 */
export function whatsappNumber(phone: string | null | undefined): string | null {
    let digits = (phone ?? '').replace(/\D+/g, '');

    if (digits.length === 11 && digits.startsWith('0')) {
        digits = digits.slice(1);
    }

    if (digits.length === 10) {
        digits = `91${digits}`;
    }

    return digits.length >= 11 && digits.length <= 15 ? digits : null;
}

export function whatsappLink(phone: string | null | undefined, text: string): string | null {
    const number = whatsappNumber(phone);

    return number ? `https://wa.me/${number}?text=${encodeURIComponent(text)}` : null;
}

const SITE = 'takecareconnect.com';

function hello(name: string | null | undefined): string {
    const first = name?.trim().split(/\s+/)[0];

    return first ? `Namaste ${first},` : 'Namaste,';
}

/** First contact with a lead. */
export function introMessage({ contact, business, sender }: { contact: string | null; business: string; sender: string }) {
    return [
        hello(contact),
        '',
        `This is ${sender} from Take Care Connect (${SITE}). We are a non-profit that features small businesses like ${business} — free of cost — with an interview and a story on our website and social pages.`,
        '',
        'Could we speak for a few minutes about telling your story?',
    ].join('\n');
}

interface InterviewDetails {
    subject: string | null;
    title: string;
    when: string;
    mode: string | null;
    location: string | null;
    meetingUrl: string | null;
    anchor: string | null;
    sender: string;
}

function where({ mode, location, meetingUrl }: Pick<InterviewDetails, 'mode' | 'location' | 'meetingUrl'>): string {
    if (mode === 'google_meet') {
        return meetingUrl ? `on Google Meet: ${meetingUrl}` : 'on Google Meet (we will send the link)';
    }

    return location ? `at ${location}` : 'in person';
}

/** Sent when the interview is booked. */
export function confirmationMessage(details: InterviewDetails) {
    return [
        hello(details.subject),
        '',
        `Your interview with Take Care Connect is confirmed for ${details.when}, ${where(details)}.`,
        details.anchor ? `${details.anchor} will be speaking with you.` : null,
        '',
        'It takes about half an hour. Please keep a few photos of your work handy. If the time does not suit you, just reply here and we will move it.',
        '',
        `— ${details.sender}, Take Care Connect`,
    ]
        .filter((line) => line !== null)
        .join('\n');
}

/** Sent the day before, or on the morning of. */
export function reminderMessage(details: InterviewDetails) {
    return [
        hello(details.subject),
        '',
        `A quick reminder of your Take Care Connect interview on ${details.when}, ${where(details)}.`,
        '',
        'Looking forward to hearing your story.',
        '',
        `— ${details.sender}, Take Care Connect`,
    ].join('\n');
}

/** Sent once their story is published. */
export function storyMessage({ subject, url, sender }: { subject: string | null; url: string; sender: string }) {
    return [
        hello(subject),
        '',
        `Your story is now live on Take Care Connect: ${url}`,
        '',
        'Thank you for sharing it with us. Please share it with your customers and friends — every share helps your business reach more people.',
        '',
        `— ${sender}, Take Care Connect`,
    ].join('\n');
}

/** Sends a proposal: the signed link opens it without an account. */
export function proposalMessage({
    contact,
    title,
    url,
    validUntil,
    sender,
}: {
    contact: string | null;
    title: string;
    url: string;
    validUntil: string | null;
    sender: string;
}) {
    return [
        hello(contact),
        '',
        `As discussed, here is our proposal for ${title}:`,
        url,
        '',
        validUntil ? `It is valid until ${validUntil}. Happy to walk you through it or adjust anything.` : 'Happy to walk you through it or adjust anything.',
        '',
        `— ${sender}, Take Care Connect`,
    ].join('\n');
}
