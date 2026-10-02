import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:maa_sarada/core/store/local_store.dart';
import 'package:maa_sarada/core/state/browsing_controller.dart';
import 'package:maa_sarada/core/state/wishlist_controller.dart';
import 'package:maa_sarada/data/static/static_categories.dart';
import 'package:maa_sarada/data/static/static_notifications.dart';
import 'package:maa_sarada/data/static/static_orders.dart';
import 'package:maa_sarada/data/static/static_products.dart';
import 'package:maa_sarada/data/static/static_promos.dart';
import 'package:maa_sarada/data/static/static_reviews.dart';
import 'package:maa_sarada/data/static/static_rooms.dart';
import 'package:maa_sarada/data/static/static_textures.dart';
import 'package:maa_sarada/data/static/static_user.dart';

/// Every asset path the app can reference must exist on disk — v1 ships with
/// local media only, so a missing file is a runtime grey box, not a 404.
void expectAssetExists(String path, String context) {
  expect(File(path).existsSync(), isTrue, reason: '$context -> missing $path');
}

void main() {
  test('demo catalogue meets the spec minimums', () {
    expect(kProducts.length, greaterThanOrEqualTo(20));
    expect(kCategories.length, greaterThanOrEqualTo(8));
    expect(kRooms.length, greaterThanOrEqualTo(5));
    expect(kReviews.length, greaterThanOrEqualTo(15));
    expect(kOrders.length, greaterThanOrEqualTo(5));
    expect(kCoupons.length, greaterThanOrEqualTo(5));
    expect(kOffers.length, greaterThanOrEqualTo(5));
    expect(kTextures.length, greaterThanOrEqualTo(5));
    expect(kNotifications, isNotEmpty);
    expect(kBanners, isNotEmpty);
    expect(kDemoAddresses, isNotEmpty);
  });

  test('the wishlist and recently-viewed seeds are populated on first run', () {
    final wishlist = WishlistController(LocalStore.memory());
    expect(wishlist.ids.length, greaterThanOrEqualTo(5));
    for (final id in wishlist.ids) {
      expect(kProducts.any((p) => p.id == id), isTrue, reason: 'wishlist $id');
    }
    wishlist.dispose();

    final browsing = BrowsingController(LocalStore.memory());
    expect(browsing.recentlyViewed.length, greaterThanOrEqualTo(5));
    for (final id in browsing.recentlyViewed) {
      expect(kProducts.any((p) => p.id == id), isTrue, reason: 'recent $id');
    }
    browsing.dispose();
  });

  test('product ids are unique and every field is populated', () {
    expect(kProducts.map((p) => p.id).toSet().length, kProducts.length);
    for (final p in kProducts) {
      expect(p.name, isNotEmpty, reason: p.id);
      expect(p.description.length, greaterThan(40), reason: '${p.id} copy');
      expect(p.pricePerSqFt, greaterThan(0), reason: p.id);
      expect(p.originalPrice, greaterThanOrEqualTo(p.pricePerSqFt), reason: p.id);
      expect(p.rating, inInclusiveRange(0, 5), reason: p.id);
      expect(p.reviewCount, greaterThan(0), reason: p.id);
      expect(p.gallery, isNotEmpty, reason: p.id);
      expect(p.stock, greaterThanOrEqualTo(0), reason: p.id);
      expect(p.slabSqFt, greaterThan(0), reason: p.id);
    }
  });

  test('every product points at a real category and a real texture', () {
    final categoryIds = kCategories.map((c) => c.id).toSet();
    final textureIds = kTextures.map((t) => t.id).toSet();
    for (final p in kProducts) {
      expect(categoryIds, contains(p.categoryId), reason: p.id);
      expect(textureIds, contains(p.textureId),
          reason: '${p.id} — the only link between shop and visualiser');
    }
  });

  test('every category holds at least one product', () {
    for (final c in kCategories) {
      expect(kProducts.any((p) => p.categoryId == c.id), isTrue, reason: c.id);
    }
  });

  test('reviews and orders reference real products', () {
    final ids = kProducts.map((p) => p.id).toSet();
    for (final r in kReviews) {
      expect(ids, contains(r.productId), reason: r.id);
      expect(r.rating, inInclusiveRange(1, 5), reason: r.id);
      expect(r.body, isNotEmpty, reason: r.id);
    }
    for (final o in kOrders) {
      expect(o.items, isNotEmpty, reason: o.id);
      for (final line in o.items) {
        expect(ids, contains(line.productId), reason: '${o.id}/${line.productId}');
      }
      expect(o.timeline, isNotEmpty, reason: '${o.id} needs a tracking timeline');
      expect(o.total, greaterThan(0), reason: o.id);
    }
  });

  test('rooms declare applyable surfaces with real default textures', () {
    final textureIds = kTextures.map((t) => t.id).toSet();
    for (final room in kRooms) {
      expect(room.surfaces, isNotEmpty, reason: room.id);
      expect(room.hotspots, isNotEmpty, reason: room.id);
      expect(room.width, greaterThan(0), reason: room.id);
      expect(room.depth, greaterThan(0), reason: room.id);
      expect(room.height, greaterThan(0), reason: room.id);
      expect(room.surfaces.any((s) => s.applyable), isTrue, reason: room.id);

      final surfaceIds = room.surfaces.map((s) => s.id).toSet();
      for (final s in room.surfaces) {
        expect(textureIds, contains(s.defaultTextureId), reason: '${room.id}/${s.id}');
        expect(s.areaSqFt, greaterThan(0), reason: '${room.id}/${s.id}');
        expect(s.tileMetres, greaterThan(0), reason: '${room.id}/${s.id}');
      }
      for (final h in room.hotspots) {
        expect(surfaceIds, contains(h.surfaceId), reason: '${room.id}/${h.id}');
      }
    }
  });

  test('the camera starts inside the room in every scene', () {
    for (final room in kRooms) {
      expect(room.eyeHeight, greaterThan(0));
      expect(room.eyeHeight, lessThan(room.height), reason: '${room.id} eye height');
      expect(room.fov, inInclusiveRange(40, 100), reason: '${room.id} fov');
    }
  });

  test('every referenced media file ships in the repo', () {
    for (final p in kProducts) {
      expectAssetExists(p.image, 'product ${p.id}');
      for (final g in p.gallery) {
        expectAssetExists(g, 'gallery ${p.id}');
      }
    }
    for (final c in kCategories) {
      expectAssetExists(c.image, 'category ${c.id}');
    }
    for (final t in kTextures) {
      expectAssetExists(t.asset, 'texture ${t.id}');
      expectAssetExists(t.thumb, 'texture thumb ${t.id}');
    }
    for (final r in kRooms) {
      expectAssetExists(r.preview, 'room ${r.id}');
      expectAssetExists(r.thumb, 'room thumb ${r.id}');
    }
    for (final b in [...kBanners, ...kInspiration]) {
      expectAssetExists(b.image, 'banner ${b.id}');
    }
    for (final o in kOffers) {
      expectAssetExists(o.image, 'offer ${o.id}');
    }
  });

  test('no static record points at a remote URL — v1 is fully offline', () {
    final paths = <String>[
      for (final p in kProducts) ...[p.image, ...p.gallery],
      for (final c in kCategories) c.image,
      for (final t in kTextures) ...[t.asset, t.thumb],
      for (final r in kRooms) ...[r.preview, r.thumb],
      for (final b in [...kBanners, ...kInspiration]) b.image,
      for (final o in kOffers) o.image,
    ];
    for (final path in paths) {
      expect(path.startsWith('http'), isFalse, reason: path);
    }
  });
}
