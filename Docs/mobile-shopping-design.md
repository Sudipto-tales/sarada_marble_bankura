# Mobile shopping design

The mobile storefront combines a dense shopping feed with the existing warm stone palette, generous photography, quieter typography and consistent rounded surfaces.

## Shared design

- Use consistent 24 px card corners and anti-aliased image clipping. Clip the image placeholder background with the photograph to prevent square edges showing behind rounded images.
- Keep a compact pill navigation floating over the page, with no full-width background panel. Use 9 px menu labels and custom rounded line symbols.
- Preserve light and dark themes, reduced motion, accessible touch targets and cart badges.

## Home page

1. Large green deals showcase: four photographic deals, actual catalog discounts and an all-deals link.
2. Stone Atelier introduction and existing campaign carousel.
3. Categories, shopping tools, featured collection and coupons.
4. Today's deals.
5. Buy again from non-cancelled, non-returned order history.
6. Recommendations in categories previously purchased, excluding purchased items.
7. Trending products, room inspiration, best sellers, recently viewed and support.

Purchase-based sections are hidden when there is no matching history. Refresh updates the feed from the repositories.

## Product page

Retain the main gallery, price, area selector, delivery, specifications and reviews, then show:

1. You might also like: four recommendations in a swipeable rail.
2. Better together: selected stone plus an available companion, selected area of each, combined price, existing catalog savings and an action adding both to the cart.
3. Explore this stone: product-specific slab, macro, tile and room photography with labelled cards opening the zoom gallery.
4. Similar products: eight recommendations in four rows of two on mobile.
5. Shop by brands: horizontal photographic collection cards opening the matching catalog.

Combo savings use existing product markdowns. No additional discount or promotion expiry is invented. Gallery imagery uses the selected product's existing asset set.

## Validation

Run Flutter analysis and the app test suite. Exercise the shell at 320 and 390 px in both themes, scroll through the new product sections and verify combo cart quantities.
