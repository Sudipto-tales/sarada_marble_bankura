import '../models/address.dart';
import '../models/app_user.dart';

/// Prototype-only account. Local auth: the "password" lives in AppConfig and is
/// never sent anywhere. Replace with a real auth repository later.
final AppUser kDemoUser = AppUser(
  id: 'u_demo',
  name: 'Aarav Sharma',
  email: 'demo@maasarada.com',
  phone: '+91 98290 12345',
  avatarInitials: 'AS',
  memberSince: DateTime(2023, 4, 18),
);

const List<Address> kDemoAddresses = [
  Address(
    id: 'a1',
    name: 'Aarav Sharma',
    phone: '+91 98290 12345',
    line1: 'Flat 1204, Aurum Residences',
    line2: 'Sector 62, Near City Centre',
    city: 'Noida',
    state: 'Uttar Pradesh',
    pincode: '201309',
    label: 'Home',
    isDefault: true,
  ),
  Address(
    id: 'a2',
    name: 'Aarav Sharma',
    phone: '+91 98290 12345',
    line1: 'Sharma Interiors, Shop 14',
    line2: 'Marble Market Road',
    city: 'Kishangarh',
    state: 'Rajasthan',
    pincode: '305801',
    label: 'Work',
  ),
  Address(
    id: 'a3',
    name: 'Priya Sharma',
    phone: '+91 98110 44556',
    line1: 'Villa 7, Palm Grove',
    line2: 'Off Sohna Road',
    city: 'Gurugram',
    state: 'Haryana',
    pincode: '122018',
    label: 'Site',
  ),
];
