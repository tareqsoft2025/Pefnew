package com.pef.fleet.data

import android.content.Context
import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Entity(tableName = "missions")
data class MissionEntity(
    @PrimaryKey val id: Long,
    val missionNo: String, val title: String, val status: String, val serverStatus: String, val version: Int,
    val scheduledStart: String, val destLat: Double?, val destLon: Double?, val arrivalRadiusM: Int,
    val proofPolicy: String, val odometerPhotoRequired: Boolean, val vehicleId: Long?, val vehiclePlate: String?,
    val vehiclePhotoRequired: Boolean, val payload: String,
    /** true = قرار محلي بانتظار تأكيد الخادم (لا يُعتبر مؤكدًا مركزيًا) */
    val pendingLocal: Boolean = false, val conflictNote: String? = null, val updatedMs: Long = System.currentTimeMillis(),
)

@Entity(tableName = "trips")
data class TripEntity(
    @PrimaryKey val clientTripId: String, val startedMs: Long, val closed: Boolean = false, val serverTripId: Long? = null,
    val segmentId: Long? = null, val vehicleId: Long? = null, val startOdometer: Double? = null, val startOdoPending: Boolean = true,
)

@Entity(tableName = "gps_points", indices = [Index("clientTripId", "uploaded")])
data class GpsPointEntity(
    @PrimaryKey val id: String, val clientTripId: String, val tsMs: Long, val lat: Double, val lon: Double,
    val accuracy: Float?, val speed: Float?, val bearing: Float?, val source: String, val uploaded: Boolean = false,
)

@Entity(tableName = "outbox", indices = [Index(value = ["eventId"], unique = true)])
data class OutboxEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0, val eventId: String, val type: String, val payload: String,
    val createdMs: Long, val attempts: Int = 0, val nextAttemptMs: Long = 0, val lastError: String? = null,
    /** pending | rejected (خطأ نهائي يُعرض في مركز المزامنة) */
    val state: String = "pending",
)

@Entity(tableName = "photos")
data class PhotoEntity(
    @PrimaryKey val id: String, val ownerType: String, val ownerRef: String, val path: String, val capturedMs: Long,
    /** pending | uploaded | failed */ val status: String = "pending", val attempts: Int = 0, val lastError: String? = null,
)

@Entity(tableName = "sos")
data class SosEntity(
    @PrimaryKey val eventId: String, val createdMs: Long, val lat: Double?, val lon: Double?, val accuracy: Float?, val locMs: Long?,
    val lastKnown: Boolean, val type: String?, val notes: String?,
    /** pending_connection | received */ val status: String = "pending_connection",
    val incidentId: Long? = null, val incidentNo: String? = null, val serverStatus: String? = null, val receivedAt: String? = null,
)

@Entity(tableName = "notifications")
data class NotificationEntity(@PrimaryKey val id: Long, val type: String, val title: String, val body: String?, val createdAt: String, val read: Boolean)

@Entity(tableName = "stop_prompts")
data class StopPromptEntity(@PrimaryKey val startedMs: Long, val clientTripId: String, val promptDue: Boolean = false, val explained: Boolean = false)

@Dao
interface MissionDao {
    @Query("SELECT * FROM missions ORDER BY scheduledStart") fun all(): Flow<List<MissionEntity>>
    @Query("SELECT * FROM missions ORDER BY scheduledStart") suspend fun allNow(): List<MissionEntity>
    @Query("SELECT * FROM missions WHERE id=:id") suspend fun get(id: Long): MissionEntity?
    @Query("SELECT * FROM missions WHERE id=:id") fun observe(id: Long): Flow<MissionEntity?>
    @Upsert suspend fun upsert(m: MissionEntity)
    @Query("DELETE FROM missions WHERE id NOT IN (:keep) AND pendingLocal=0") suspend fun deleteNotIn(keep: List<Long>)
}

@Dao
interface TripDao {
    @Query("SELECT * FROM trips WHERE closed=0 LIMIT 1") suspend fun open(): TripEntity?
    @Query("SELECT * FROM trips WHERE closed=0 LIMIT 1") fun observeOpen(): Flow<TripEntity?>
    @Query("SELECT * FROM trips WHERE clientTripId=:id") suspend fun get(id: String): TripEntity?
    @Upsert suspend fun upsert(t: TripEntity)
    @Query("SELECT * FROM trips ORDER BY startedMs DESC LIMIT 50") fun recent(): Flow<List<TripEntity>>
}

@Dao
interface GpsDao {
    @Insert(onConflict = OnConflictStrategy.IGNORE) suspend fun insert(p: GpsPointEntity)
    @Query("SELECT * FROM gps_points WHERE clientTripId=:trip AND uploaded=0 ORDER BY tsMs LIMIT :limit") suspend fun pending(trip: String, limit: Int): List<GpsPointEntity>
    @Query("SELECT DISTINCT clientTripId FROM gps_points WHERE uploaded=0") suspend fun tripsWithPending(): List<String>
    @Query("UPDATE gps_points SET uploaded=1 WHERE id IN (:ids)") suspend fun markUploaded(ids: List<String>)
    @Query("SELECT COUNT(*) FROM gps_points WHERE uploaded=0") fun pendingCount(): Flow<Int>
    @Query("SELECT * FROM gps_points ORDER BY tsMs DESC LIMIT 1") suspend fun last(): GpsPointEntity?
    @Query("SELECT * FROM gps_points WHERE clientTripId=:trip ORDER BY tsMs DESC LIMIT 300") suspend fun recent(trip: String): List<GpsPointEntity>
    @Query("DELETE FROM gps_points WHERE uploaded=1 AND tsMs < :olderThan") suspend fun purgeUploaded(olderThan: Long)
}

@Dao
interface OutboxDao {
    @Insert suspend fun insert(e: OutboxEntity): Long
    @Query("SELECT * FROM outbox WHERE state='pending' ORDER BY id") suspend fun pending(): List<OutboxEntity>
    @Query("SELECT * FROM outbox ORDER BY id DESC") fun all(): Flow<List<OutboxEntity>>
    @Query("SELECT COUNT(*) FROM outbox WHERE state='pending'") fun pendingCount(): Flow<Int>
    @Query("DELETE FROM outbox WHERE eventId=:eventId") suspend fun delete(eventId: String)
    @Query("UPDATE outbox SET attempts=attempts+1, nextAttemptMs=:next, lastError=:err WHERE eventId=:eventId") suspend fun retryLater(eventId: String, next: Long, err: String?)
    @Query("UPDATE outbox SET state='rejected', lastError=:err WHERE eventId=:eventId") suspend fun markRejected(eventId: String, err: String?)
    @Query("DELETE FROM outbox WHERE state='rejected'") suspend fun clearRejected()
    @Query("SELECT COUNT(*) FROM outbox WHERE state='pending' AND type=:type AND payload LIKE :like") suspend fun hasPending(type: String, like: String): Int
    @Query("UPDATE outbox SET nextAttemptMs=0") suspend fun retryAllNow()
}

@Dao
interface PhotoDao {
    @Upsert suspend fun upsert(p: PhotoEntity)
    @Query("SELECT * FROM photos WHERE status<>'uploaded' ORDER BY capturedMs") suspend fun pending(): List<PhotoEntity>
    @Query("SELECT * FROM photos ORDER BY capturedMs DESC") fun all(): Flow<List<PhotoEntity>>
    @Query("SELECT COUNT(*) FROM photos WHERE status<>'uploaded'") fun pendingCount(): Flow<Int>
    @Query("SELECT * FROM photos WHERE ownerType=:t AND ownerRef=:r") fun forOwner(t: String, r: String): Flow<List<PhotoEntity>>
}

@Dao
interface SosDao {
    @Upsert suspend fun upsert(s: SosEntity)
    @Query("SELECT * FROM sos ORDER BY createdMs DESC") fun all(): Flow<List<SosEntity>>
    @Query("SELECT * FROM sos WHERE eventId=:id") suspend fun get(id: String): SosEntity?
    @Query("SELECT * FROM sos WHERE eventId=:id") fun observe(id: String): Flow<SosEntity?>
}

@Dao
interface NotificationDao {
    @Upsert suspend fun upsertAll(n: List<NotificationEntity>)
    @Query("SELECT * FROM notifications ORDER BY id DESC") fun all(): Flow<List<NotificationEntity>>
    @Query("UPDATE notifications SET `read`=1 WHERE id=:id") suspend fun markRead(id: Long)
}

@Dao
interface StopPromptDao {
    @Upsert suspend fun upsert(s: StopPromptEntity)
    @Query("SELECT * FROM stop_prompts WHERE promptDue=1 AND explained=0 ORDER BY startedMs DESC") fun due(): Flow<List<StopPromptEntity>>
    @Query("UPDATE stop_prompts SET explained=1 WHERE startedMs=:ms") suspend fun markExplained(ms: Long)
}

@Database(entities = [MissionEntity::class, TripEntity::class, GpsPointEntity::class, OutboxEntity::class, PhotoEntity::class, SosEntity::class, NotificationEntity::class, StopPromptEntity::class], version = 1, exportSchema = false)
abstract class AppDb : RoomDatabase() {
    abstract fun missions(): MissionDao; abstract fun trips(): TripDao; abstract fun gps(): GpsDao; abstract fun outbox(): OutboxDao
    abstract fun photos(): PhotoDao; abstract fun sos(): SosDao; abstract fun notifications(): NotificationDao; abstract fun stopPrompts(): StopPromptDao

    companion object {
        fun build(c: Context): AppDb = Room.databaseBuilder(c, AppDb::class.java, "fleet.db").fallbackToDestructiveMigrationOnDowngrade().build()
    }
}
