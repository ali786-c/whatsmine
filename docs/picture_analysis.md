# Homepage picture analysis

The picture/ folder contains nine useful visual assets. They divide into three groups:

- product proof: real interface screenshots that show what WhatsMine can do;
- outcome imagery: human/customer scenes that explain the value emotionally;
- section-specific UI: campaign, Instagram automation and template visuals.

## Recommended mapping

| File | What it shows | Best homepage role | Treatment |
| --- | --- | --- | --- |
| f92239e4-3544-4f9d-861d-dd46d4f1c1c7.png | Three people collaborating around a laptop, with messaging, alert and calendar symbols | Collaboration / connected workspace feature row | Use as a wide rounded image. Keep the people and the green messaging symbol visible. |
| Smiling Shopper with Chat Support.png | Customer on a chair with WhatsApp-style conversation bubbles | Hero supporting visual or AI customer-support section | Use as the main human outcome image. Avoid adding another large robot beside it. |
| contact.png | Contacts table with search, import/export, segments, tags and opt-ins | Contacts and customer context feature | Use inside a browser frame or as a clean product screenshot. |
| stat screen.png | WhatsApp campaign report with delivery KPIs, funnel, timeline, failures and recipients | Campaign analytics / reporting section | Use as a full-width dashboard proof image; crop only the empty margins. |
| campign.png | Campaign report dashboard with recipient delivery metrics | Campaign performance feature | Use as a secondary campaign visual or a detail card beside the campaign copy. |
| automation.png | Visual order confirmation and COD verification workflow with conditions, delays and templates | Automation Builder section | This is the strongest automation asset. Use it as the large featured screenshot. |
| instgarm comment.png | Instagram comment automation list with active/paused states and trigger counts | Social automation section | Use for Instagram automation, separate from the general integrations section. |
| templete UI.png | Large Message Templates management screen with filters and approval states | WhatsApp templates section | Use as the primary templates screenshot because its text is readable. |
| templete.png | More compact templates grid with approved marketing and utility messages | Template gallery / approval proof | Use as a smaller supporting card; do not show both template images at full size in one viewport. |

## Best page structure using these assets

1. Hero: keep the robot as the brand mascot, but use Smiling Shopper with Chat Support.png as a small customer outcome panel below the hero. The robot and human image should not compete at equal size.
2. One Inbox: keep the existing inbox preview because it demonstrates the product interaction better than a static screenshot.
3. Product stack: use the existing Dashboard, Inbox, Automation and Templates demo cards. Do not repeat automation.png or templete UI.png immediately underneath.
4. Connected workspace: use f92239e4-3544-4f9d-861d-dd46d4f1c1c7.png as the human collaboration row.
5. Campaigns: use stat screen.png as the hero screenshot and campign.png as a smaller detail panel.
6. Automation: use automation.png as the only large automation screenshot.
7. Contacts: use contact.png for a short customer-context row.
8. Social automation: use instgarm comment.png as a compact proof card.
9. Templates: use one template image only—prefer templete UI.png for a feature section and reserve templete.png for a later gallery or lightbox.

## Visual consistency rules

- Copy the original files into docs/assets/product/ before wiring them into the prototype; keep the picture/ folder as the source archive.
- Use a consistent browser frame: 16px radius, 1px muted border, very soft shadow, and the same top bar across screenshots.
- Product screenshots should use object-fit: contain so UI text and controls are not cut off. Human imagery can use object-fit: cover.
- Show one large visual per section. Supporting visuals should be smaller cards or appear on interaction.
- Keep headings and benefit text in HTML. Do not bake new marketing copy into screenshots.
- Use the existing forest/lime palette for frames and labels; do not introduce the blue/purple dashboard accent colors as page background colors.
- Give every image a meaningful alt description and lazy-load images below the fold.

## Asset quality notes

The UI screenshots have strong resolution and readable interface details. automation.png, stat screen.png and templete UI.png are especially suitable for desktop feature sections. contact.png has a wide table layout and should be displayed at a larger width rather than in a narrow card. The human collaboration and shopper images are already composed as marketing visuals, so they can carry a rounded image panel without extra decoration.

The two template screenshots overlap in purpose, as do the two campaign screenshots. Showing every image in sequence would repeat the same story and make the homepage feel long. Use one primary image per capability and keep the second image behind a See a sample interaction or lightbox.
