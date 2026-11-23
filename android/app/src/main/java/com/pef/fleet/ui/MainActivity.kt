package com.pef.fleet.ui

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.*
import androidx.navigation.NavType
import androidx.navigation.compose.*
import androidx.navigation.navArgument
import com.pef.fleet.Graph
import com.pef.fleet.tracking.TrackingController
import com.pef.fleet.sync.SyncScheduler

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { FleetTheme { AppNav() } }
    }

    override fun onStart() {
        super.onStart()
        if (Graph.tokens.loggedIn) { SyncScheduler.syncNow(this); TrackingController.ensure(this) }
    }
}

@Composable
fun AppNav() {
    val nav = rememberNavController()
    val go: (String) -> Unit = { nav.navigate(it) }
    val back: () -> Unit = { nav.popBackStack() }
    NavHost(nav, startDestination = "splash") {
        composable("splash") { SplashScreen { dest -> nav.navigate(dest) { popUpTo("splash") { inclusive = true } } } }
        composable("login") { LoginScreen { nav.navigate("home") { popUpTo("login") { inclusive = true } } } }
        composable("home") { HomeScreen(go) }
        composable("missions") { MissionsScreen(go, back) }
        composable("mission/{id}", listOf(navArgument("id") { type = NavType.LongType })) { MissionDetailScreen(it.arguments!!.getLong("id"), go, back) }
        composable("odo/{kind}") { OdometerScreen(it.arguments!!.getString("kind")!!, back) }
        composable("inspection") { InspectionScreen(back) }
        composable("trip") { TripScreen(go, back) }
        composable("map") { MapScreen(back) }
        composable("stop") { StopReasonScreen(back) }
        composable("sos") { SosScreen(go, back) }
        composable("sosDetail/{id}") { SosDetailScreen(it.arguments!!.getString("id")!!, back) }
        composable("arrival/{id}", listOf(navArgument("id") { type = NavType.LongType })) { ArrivalScreen(it.arguments!!.getLong("id"), back) }
        composable("closetrip") { CloseTripScreen { nav.popBackStack("home", false) } }
        composable("sync") { SyncCenterScreen(back) }
        composable("notifications") { NotificationsScreen(back) }
        composable("history") { HistoryScreen(back) }
        composable("profile") { ProfileScreen(back) { nav.navigate("login") { popUpTo(0) } } }
        composable("help") { HelpScreen(back) }
    }
}
