import Foundation
import SwiftUI
import UIKit
import UserNotifications
import FirebaseMessaging

@MainActor
final class PushNavigation: ObservableObject {
    static let shared = PushNavigation()
    @Published private(set) var missedCallRequest = 0

    func openMissedCalls() { missedCallRequest += 1 }

    static func isMissedCall(_ data: [AnyHashable: Any]) -> Bool {
        let type = data["type"] as? String ?? data["event_type"] as? String ?? ""
        let status = data["status"] as? String ?? ""
        let screen = data["screen"] as? String ?? ""
        return type == "missed_call" || (screen == "incoming" && (status == "missed" || status == "missed_no_callback"))
    }
}

final class PushRegistration {
    static let shared = PushRegistration()
    private let tokenKey = "dashboard.ios.fcm.token"
    private let installationKey = "dashboard.ios.installation_id"
    private var registrationInProgress = false

    func activate() {
        guard UserDefaults.standard.bool(forKey: "isConnected") else { return }
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge]) { _, error in
            if let error { print("DashBoard notifications permission: \(error.localizedDescription)") }
            DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
        }
        if let cached = UserDefaults.standard.string(forKey: tokenKey), !cached.isEmpty {
            register(token: cached)
        }
    }

    func register(token: String) {
        guard !token.isEmpty else { return }
        UserDefaults.standard.set(token, forKey: tokenKey)
        guard UserDefaults.standard.bool(forKey: "isConnected") else { return }
        guard !registrationInProgress else { return }
        registrationInProgress = true
        Task {
            let settings = AppSettings()
            if let url = URL(string: settings.apiAddress), !settings.token.isEmpty {
                do {
                    try await APIClient(baseURL: url, token: settings.token)
                        .registerPush(installationID: installationID(), fcmToken: token)
                } catch {
                    print("DashBoard push registration failed: \(error.localizedDescription)")
                    // Reattempt on the next foreground activation or successful login.
                }
            }
            registrationInProgress = false
            // The callback may receive a newer token while this request is running.
            if let latest = UserDefaults.standard.string(forKey: tokenKey), latest != token {
                register(token: latest)
            }
        }
    }

    private func installationID() -> String {
        if let id = UserDefaults.standard.string(forKey: installationKey), !id.isEmpty { return id }
        let id = "ios-" + UUID().uuidString.lowercased()
        UserDefaults.standard.set(id, forKey: installationKey)
        return id
    }
}
